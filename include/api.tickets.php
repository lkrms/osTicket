<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';

class TicketApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "alert", "autorespond", "source", "topicId",
            "attachments" => array("*" =>
                array("name", "type", "data", "encoding", "size")
            ),
            "message", "ip", "priorityId",
            "system_emails" => array(
                "*" => "*"
            ),
            "thread_entry_recipients" => array (
                "*" => array("to", "cc")
            )
        );
        # Fetch dynamic form field names for the given help topic and add
        # the names to the supported request structure
        if (isset($data['topicId'])
                && ($topic = Topic::lookup($data['topicId']))
                && ($forms = $topic->getForms())) {
            foreach ($forms as $form)
                foreach ($form->getDynamicFields() as $field)
                    $supported[] = $field->get('name');
        }

        # Ticket form fields
        # TODO: Support userId for existing user
        if(($form = TicketForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        # User form fields
        if(($form = UserForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        if(!strcasecmp($format, 'email')) {
            $supported = array_merge($supported, array('header', 'mid',
                'emailId', 'to-email-id', 'ticketId', 'reply-to', 'reply-to-name',
                'in-reply-to', 'references', 'thread-type', 'system_emails',
                'mailflags' => array('bounce', 'auto-reply', 'spam', 'viral'),
                'recipients' => array('*' => array('name', 'email', 'source'))
                ));

            $supported['attachments']['*'][] = 'cid';
        }

        return $supported;
    }

    /*
     Validate data - overwrites parent's validator for additional validations.
    */
    function validate(&$data, $format, $strict=true) {
        global $ost;

        //Call parent to Validate the structure
        if(!parent::validate($data, $format, $strict) && $strict)
            $this->exerr(400, __('Unexpected or invalid data received'));

        // Use the settings on the thread entry on the ticket details
        // form to validate the attachments in the email
        $tform = TicketForm::objects()->one()->getForm();
        $messageField = $tform->getField('message');
        $fileField = $messageField->getWidget()->getAttachments();

        // Nuke attachments IF API files are not allowed.
        if (!$messageField->isAttachmentsEnabled())
            $data['attachments'] = array();

        //Validate attachments: Do error checking... soft fail - set the error and pass on the request.
        if ($data['attachments'] && is_array($data['attachments'])) {
            foreach($data['attachments'] as &$file) {
                if ($file['encoding'] && !strcasecmp($file['encoding'], 'base64')) {
                    if(!($file['data'] = base64_decode($file['data'], true)))
                        $file['error'] = sprintf(__('%s: Poorly encoded base64 data'),
                            Format::htmlchars($file['name']));
                }
                // Validate and save immediately
                try {
                    $F = $fileField->uploadAttachment($file);
                    $file['id'] = $F->getId();
                }
                catch (FileUploadError $ex) {
                    $file['error'] = $file['name'] . ': ' . $ex->getMessage();
                }
            }
            unset($file);
        }

        return true;
    }


    function create($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $ticket = $this->createTicket($this->getRequest($format));
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));

        $this->response(201, $ticket->getNumber());
    }

    /**
     * For now, return all open tickets in one request.
     *
     * TODO: add optional filters, and paging.
     *
     * @param string $format Expected to be `json`.
     */
    function getTickets($format)
    {
        if (!$this->requireApiKey())
            return $this->exerr(401, __('API key not authorized'));

        $sql = 'select
    t.ticket_id,
    t.number as ticket_number,
    td.subject,
    ts.id as status_id,
    ts.name as status_name,
    ts.state as status_state,
    tp.priority_id,
    tp.priority,
    tp.priority_desc,
    tp.priority_urgency,
    t.created,
    t.duedate,
    u.id as user_id,
    u.name as user_name,
    coalesce(ue.address,
    ue_default.address) as user_email,
    o.id as org_id,
    o.name as org_name
from
    ' . TICKET_TABLE . ' t
inner join ' . TICKET_CDATA_TABLE . ' td on
    t.ticket_id = td.ticket_id
inner join ' . TICKET_STATUS_TABLE . ' ts on
    t.status_id = ts.id
inner join ' . TICKET_PRIORITY_TABLE . ' tp on
    td.priority = tp.priority_id
inner join ' . USER_TABLE . ' u on
    t.user_id = u.id
left join ' . USER_EMAIL_TABLE . ' ue on
    t.user_email_id = ue.id
left join ' . USER_EMAIL_TABLE . ' ue_default on
    u.default_email_id = ue_default.id
left join ' . ORGANIZATION_TABLE . ' o on
    u.org_id = o.id
where
    ts.state = \'open\'
order by
    tp.priority_urgency,
    t.created';

        if (!($res = db_query($sql)))
            return $this->exerr(500, __('Unable to retrieve ticket data: unknown error'));

        $tickets = [];

        while (list(
            $ticketId,
            $ticketNumber,
            $subject,
            $statusId,
            $statusName,
            $statusState,
            $priorityId,
            $priorityName,
            $priorityDesc,
            $priorityUrgency,
            $created,
            $due,
            $userId,
            $userName,
            $userEmail,
            $orgId,
            $orgName
        ) = db_fetch_row($res)) {

            $status = is_null($statusId) ? null : [
                'status_id' => $statusId,
                'status_name' => $statusName,
                'status_state' => $statusState
            ];

            $priority = is_null($priorityId) ? null : [
                'priority_id' => $priorityId,
                'priority_name' => $priorityName,
                'priority_desc' => $priorityDesc,
                'priority_urgency' => $priorityUrgency
            ];

            $user = is_null($userId) ? null : [
                'user_id' => $userId,
                'user_name' => $userName,
                'user_email' => $userEmail
            ];

            $org = is_null($orgId) ? null : [
                'org_id' => $orgId,
                'org_name' => $orgName
            ];

            $tickets[] = [
                'ticket_id' => $ticketId,
                'ticket_number' => $ticketNumber,
                'subject' => $subject,
                'status' => $status,
                'priority' => $priority,
                'user' => $user,
                'org' => $org,
                'created' => $created,
                'due' => $due
            ];
        }

        $this->response(201, json_encode($tickets), 'application/json');
    }

    /* private helper functions */

    function response($code, $resp, $contentType = 'text/html')
    {
        Http::response($code, $resp, $contentType);
        exit();
    }

    function createTicket($data) {

        # Pull off some meta-data
        $alert       = (bool) (isset($data['alert'])       ? $data['alert']       : true);
        $autorespond = (bool) (isset($data['autorespond']) ? $data['autorespond'] : true);

        # Assign default value to source if not defined, or defined as NULL
        $data['source'] = isset($data['source']) ? $data['source'] : 'API';

        # Create the ticket with the data (attempt to anyway)
        $errors = array();

        $ticket = Ticket::create($data, $errors, $data['source'], $autorespond, $alert);
        # Return errors (?)
        if (count($errors)) {
            if(isset($errors['errno']) && $errors['errno'] == 403)
                return $this->exerr(403, __('Ticket denied'));
            else
                return $this->exerr(
                        400,
                        __("Unable to create new ticket: validation errors").":\n"
                        .Format::array_implode(": ", "\n", $errors)
                        );
        } elseif (!$ticket) {
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));
        }

        return $ticket;
    }

    function processEmail($data=false) {

        if (!$data)
            $data = $this->getEmailRequest();

        $seen = false;
        if (($entry = ThreadEntry::lookupByEmailHeaders($data, $seen))
            && ($message = $entry->postEmail($data))
        ) {
            if ($message instanceof ThreadEntry) {
                return $message->getThread()->getObject();
            }
            else if ($seen) {
                // Email has been processed previously
                return $entry->getThread()->getObject();
            }
        }

        // Allow continuation of thread without initial message or note
        elseif (($thread = Thread::lookupByEmailHeaders($data))
            && ($message = $thread->postEmail($data))
        ) {
            return $thread->getObject();
        }

        // All emails which do not appear to be part of an existing thread
        // will always create new "Tickets". All other objects will need to
        // be created via the web interface or the API
        return $this->createTicket($data);
    }

}

//Local email piping controller - no API key required!
class PipeApiController extends TicketApiController {

    //Overwrite grandparent's (ApiController) response method.
    function response($code, $resp) {

        //Use postfix exit codes - instead of HTTP
        switch($code) {
            case 201: //Success
                $exitcode = 0;
                break;
            case 400:
                $exitcode = 66;
                break;
            case 401: /* permission denied */
            case 403:
                $exitcode = 77;
                break;
            case 415:
            case 416:
            case 417:
            case 501:
                $exitcode = 65;
                break;
            case 503:
                $exitcode = 69;
                break;
            case 500: //Server error.
            default: //Temp (unknown) failure - retry
                $exitcode = 75;
        }

        //echo "$code ($exitcode):$resp";
        //We're simply exiting - MTA will take care of the rest based on exit code!
        exit($exitcode);
    }

    function  process() {
        $pipe = new PipeApiController();
        if(($ticket=$pipe->processEmail()))
           return $pipe->response(201, $ticket->getNumber());

        return $pipe->exerr(416, __('Request failed - retry again!'));
    }
}

?>

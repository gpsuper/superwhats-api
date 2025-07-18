<?php

namespace App\Http\Presenters;

use App\Domain\Entities\Email;

class EmailPresenter
{
    public static function present(Email $email): array
    {
        return [
            'id' => $email->getId(),
            'account_id' => $email->getAccountId(),
            'from' => $email->getData()->getFrom(),
            'to' => $email->getData()->getTo(),
            'cc' => $email->getData()->getCc(),
            'bcc' => $email->getData()->getBcc(),
            'subject' => $email->getData()->getSubject(),
            'body' => $email->getData()->getBody(),
            'direction' => $email->getDirection(),
            'folder_id' => $email->getFolderId(),
            'thread_id' => $email->getThreadId(),
            'origin' => $email->getOrigin(),
            'processed_at' => $email->getProcessedAt()->format('Y-m-d H:i:s'),
            'read' => $email->getRead(),
            'read_at' => $email->getReadAt(),
            'attachments' => $email->getData()->getAttachments()
        ];
    }
}

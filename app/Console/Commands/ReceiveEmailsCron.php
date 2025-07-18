<?php

namespace App\Console\Commands;

use App\Domain\Enums\EmailDirectionEnum;
use App\Domain\Enums\EmailFolderEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

class ReceiveEmailsCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:receive-emails-cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Salva todos os emails recebidos no banco de dados MongoDB';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Evita timeout – se necessário, ajuste o tempo máximo de execução.
        set_time_limit(0);

        // Obtém até 50 contas de email ativas para o módulo desejado.
        $emailAccounts = DB::table('user_accounts')
            ->whereNotNull('password')
            ->orderBy('last_verification', 'asc')
            ->limit(50)
            ->get();

        if ($emailAccounts->isEmpty()) {
            $this->info("Nenhuma conta de email encontrada.");
            return;
        }

        foreach ($emailAccounts as $account) {
            try {
                $this->processEmailAccount($account);
            } catch (\Exception $e) {
                echo ("Erro ao processar a conta {$account->id}: " . $e->getMessage());
            }
        }

        $this->info("Processamento de emails concluído.");
    }

    /**
     * Processa uma conta de email: conecta via IMAP, baixa e salva os emails.
     */
    protected function processEmailAccount($account)
    {
        // Dados da conta
        $server = "mail.gruposuper.com.br";
        $port = "995";
        $emailUser = $account->email ?? null;
        $password = $account->password ?? null;

        if (!$server || !$port || !$emailUser || !$password) {
            echo ("Dados insuficientes para a conta {$account->id}");
            return;
        }

        // Atualiza a data de última verificação da conta
        DB::table('user_accounts')
            ->where('id', $account->id)
            ->update(['last_verification' => now()]);

        // Abre a conexão IMAP
        $mailbox = $this->openImapMailbox($server, $port, $emailUser, $password);
        if (!$mailbox) {
            echo ("Falha ao conectar à caixa de email {$emailUser} no servidor {$server}:{$port}");
            return;
        }

        // Busca mensagens não lidas usando UID
        $emailsUids = imap_search($mailbox, 'UNSEEN', SE_UID);
        if (!$emailsUids) {
            $this->closeMailbox($mailbox);
            return;
        }

        // Cria um array de remetentes para consulta à API
        $senders = [];
        foreach ($emailsUids as $uid) {
            $message = $this->retrieveMessage($mailbox, $uid);
            if (isset($message['mailboxFrom']) && isset($message['hostFrom'])) {
                $senders[] = trim($message['mailboxFrom'] . '@' . $message['hostFrom']);
            }
        }
        $senders = array_unique($senders);

        // Consulta a API para obter mapeamento de franquia e blacklist
        $apiUrl = config('services.smail_api_url');

        $franquiaResponse = $this->communicateApi([
            'metodo' => 'smail_to_franquia',
            'emails' => $senders,
        ], $apiUrl);

        $franquiaMapping = isset($franquiaResponse->lista_email) ? (array) $franquiaResponse->lista_email : [];

        $blacklistResponse = $this->communicateApi([
            'metodo' => 'smail_blacklist',
            'emails' => $senders,
        ], $apiUrl);

        $blacklist = isset($blacklistResponse->lista_email) ? (array) $blacklistResponse->lista_email : [];

        // Processa cada email encontrado
        foreach ($emailsUids as $uid) {
            $message = $this->retrieveMessage($mailbox, $uid);

            $from = trim($message['mailboxFrom'] . '@' . $message['hostFrom']);
            $to = $message['tocompleto'] ?? [];
            $cc = $message['cccompleto'] ?? [];
            $bcc = $message['bcccompleto'] ?? [];
            $subject = $this->fixSubject($message['subject'] ?? '');
            $date = $message['date'] ?? date('Y-m-d H:i:s');
            $body = $this->getBody($uid, $mailbox);
            $threadId = $message['threadId'] ?? '';

            // Despreza mensagens da blacklist ou com data anterior a hoje
            if (in_array($from, $blacklist)) {
                continue;
            }
            if (strtotime($date) < strtotime('today')) {
                continue;
            }

            $duplicate = DB::table('emails')
                ->where('from', $from)
                ->where('to', $to)
                ->where('subject', $subject)
                ->where('body', $body)
                ->exists();

            if ($duplicate) {
                continue;
            }

            // Prepara os dados a serem inseridos
            $emailData = [
                'from' => $from,
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc,
                'attachments' => [],
                'subject' => $subject,
                'body' => $body,
                'processedAt' => $date,
                'threadId' => $threadId,
                'received' => EmailDirectionEnum::RECEIVED,
                'folder' => EmailFolderEnum::INBOX,
                'isDeleted' => false,
                'readAt' => null,
                'isRead' => false,
            ];

            // Se o remetente estiver associado a uma franquia, ajusta os dados
            if (isset($franquiaMapping[$from])) {
                $franquia = $franquiaMapping[$from];
                $emailData['id_franquia'] = $franquia->id ?? null;
                if (isset($franquia->email)) {
                    $emailData['to_email'] = $franquia->email;
                }
            }

            DB::table('emails')->insert($emailData);
        }

        // Limpa erros do IMAP e fecha a conexão de forma segura
        $this->closeMailbox($mailbox);
    }

    /**
     * Abre a conexão IMAP.
     */
    protected function openImapMailbox($server, $port, $user, $password)
    {
        // Monta o caminho da caixa de email (ajuste conforme necessário)
        $mailboxPath = "{" . $server . ":" . $port . "/pop3/ssl/novalidate-cert}INBOX";

        // Configura os timeouts do IMAP
        imap_timeout(IMAP_OPENTIMEOUT, 60);
        imap_timeout(IMAP_READTIMEOUT, 60);
        imap_timeout(IMAP_WRITETIMEOUT, 60);
        imap_timeout(IMAP_CLOSETIMEOUT, 60);

        return @imap_open($mailboxPath, $user, $password);
    }

    /**
     * Fecha a conexão IMAP de forma segura, evitando erros em shutdown.
     * 
     * Note que passamos a variável por referência para que ela seja zerada.
     */
    protected function closeMailbox(&$mailbox)
    {
        // Limpa a fila de erros e alertas do IMAP
        imap_errors();
        imap_alerts();

        if (!empty($mailbox) && is_resource($mailbox)) {
            // Fechar a conexão diretamente sem tentar o imap_ping
            @imap_close($mailbox, CL_EXPUNGE);
        }
        // Zera a variável para evitar fechamento posterior no shutdown
        $mailbox = null;
    }


    /**
     * Extrai os dados básicos do email a partir do IMAP.
     */
    protected function retrieveMessage($mailbox, $uid)
    {
        // Converte o UID para número de sequência
        $msgno = imap_msgno($mailbox, $uid);
        $header = imap_headerinfo($mailbox, $msgno);
        imap_fetchstructure($mailbox, $uid, FT_UID);

        $message = [];
        $message['mailboxFrom'] = $header->from[0]->mailbox ?? '';
        $message['hostFrom'] = $header->from[0]->host ?? '';
        $message['tocompleto'] = [$this->formatAddresses($header->to)];
        $message['cccompleto'] = isset($header->cc) ? $this->formatAddresses($header->cc) : [];
        $message['bcccompleto'] = isset($header->bcc) ? $this->formatAddresses($header->bcc) : [];
        $message['subject'] = $header->subject ?? '';
        $message['date'] = date('Y-m-d H:i:s', strtotime($header->date));

        // Primeiro, tenta utilizar o cabeçalho References
        $message['threadId'] = '';
        if (isset($header->references) && !empty($header->references)) {
            // Pode vir como string com vários IDs separados por espaço ou já como array
            $refs = is_array($header->references) ? $header->references : preg_split('/\s+/', $header->references);
            foreach ($refs as $ref) {
                if (preg_match('/<([^@]+)@superestagios\.com\.br>/', $ref, $matches)) {
                    $message['threadId'] = $matches[1];
                    break;
                }
            }
        }

        // Se não encontrou em References, tenta In-Reply-To
        if (empty($message['threadId']) && isset($header->in_reply_to) && !empty($header->in_reply_to)) {
            $inReplyTo = is_array($header->in_reply_to) ? $header->in_reply_to[0] : $header->in_reply_to;
            if (preg_match('/<([^@]+)@/', $inReplyTo, $matches)) {
                $message['threadId'] = $matches[1];
            } else {
                $message['threadId'] = trim($inReplyTo);
            }
        }

        // Se ainda estiver vazio, usa o próprio Message-ID
        if (empty($message['threadId']) && isset($header->message_id)) {
            if (preg_match('/<([^@]+)@/', $header->message_id, $matches)) {
                $message['threadId'] = $matches[1];
            } else {
                $message['threadId'] = trim($header->message_id);
            }
        }

        return $message;
    }

    /**
     * Formata um array de endereços (to ou cc) em uma string.
     */
    protected function formatAddresses($addresses)
    {
        if (!$addresses) {
            return '';
        }
        $formatted = [];
        foreach ($addresses as $addr) {
            $formatted[] = $addr->mailbox . '@' . $addr->host;
        }
        return implode(';', $formatted);
    }

    /**
     * Garante que o assunto não fique vazio.
     */
    protected function fixSubject($subject)
    {
        $subject = trim($subject);
        return empty($subject) ? '(Sem assunto)' : $subject;
    }

    /**
     * Retorna o corpo do email.
     */
    protected function getBody($uid, $mailbox)
    {
        $body = $this->getPart($mailbox, $uid, "TEXT/HTML");
        if (empty($body)) {
            $body = $this->getPart($mailbox, $uid, "TEXT/PLAIN");
        }
        return $body;
    }

    /**
     * Obtém a parte do email conforme o mime type desejado.
     */
    protected function getPart($mailbox, $uid, $mimetype, $structure = null, $partNumber = null)
    {
        if (!$structure) {
            $structure = imap_fetchstructure($mailbox, $uid, FT_UID);
        }
        if ($structure) {
            if ($mimetype == $this->getMimeType($structure)) {
                if (!$partNumber) {
                    $partNumber = 1;
                }
                $text = imap_fetchbody($mailbox, $uid, $partNumber, FT_UID);
                switch ($structure->encoding) {
                    case 3:
                        return imap_base64($text);
                    case 4:
                        return imap_qprint($text);
                    default:
                        return $text;
                }
            }
            // Se for multipart, percorre as partes recursivamente
            if ($structure->type == 1 && isset($structure->parts)) {
                foreach ($structure->parts as $index => $subStruct) {
                    $prefix = $partNumber ? $partNumber . '.' : '';
                    $data = $this->getPart($mailbox, $uid, $mimetype, $subStruct, $prefix . ($index + 1));
                    if ($data) {
                        return $data;
                    }
                }
            }
        }
        return '';
    }

    /**
     * Retorna o mime type a partir da estrutura do email.
     */
    protected function getMimeType($structure)
    {
        $primaryMimetypes = ['TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER'];
        if (isset($structure->subtype)) {
            return $primaryMimetypes[(int) $structure->type] . '/' . $structure->subtype;
        }
        return "TEXT/PLAIN";
    }

    /**
     * Comunica com a API via Guzzle.
     */
    protected function communicateApi(array $payload, $url)
    {
        try {
            $client = new Client();
            $response = $client->post($url, [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 30,
            ]);
            $body = $response->getBody()->getContents();
            return json_decode($body);
        } catch (\Exception $e) {
            echo ("Erro na comunicação com a API: " . $e->getMessage());
            return null;
        }
    }
}

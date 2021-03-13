<?php

declare(strict_types=1);

include __DIR__ . '/../libs/WebHookModule.php';
include __DIR__ . '/../libs/vendor/autoload.php';

class TelegramBot extends WebHookModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID, 'telegram/' . $InstanceID);
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('BotApiKey', '');
        $this->RegisterPropertyString('BotUsername', '');

        $this->RegisterPropertyString('AllowList', '[]');
        $this->RegisterPropertyString('ActionList', '[]');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        if ($this->ReadPropertyString('BotApiKey')) {
            $cc_id = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
            if (IPS_GetInstance($cc_id)['InstanceStatus'] == IS_ACTIVE) {
                $webhook_url = CC_GetConnectURL($cc_id) . '/hook/telegram/' . $this->InstanceID;
                try {
                    $telegram = new Longman\TelegramBot\Telegram($this->ReadPropertyString('BotApiKey'), $this->ReadPropertyString('BotUsername'));

                    $result = $telegram->setWebhook($webhook_url);
                    if (!$result->isOk()) {
                        echo $this->Translate('Setting webhook failed!');
                    }
                } catch (Longman\TelegramBot\Exception\TelegramException $e) {
                    echo $e->getMessage();
                }
            }
        }
    }

    public function SendMessage(string $Text)
    {
        // Send message to everyone
        $recipients = json_decode($this->ReadPropertyString('AllowList'), true);
        foreach ($recipients as $recipient) {
            $this->SendMessageEx($Text, strval($recipient['UserID']));
        }
    }

    public function SendMessageEx(string $Text, string $NameOrChatID)
    {
        try {
            $telegram = new Longman\TelegramBot\Telegram($this->ReadPropertyString('BotApiKey'), $this->ReadPropertyString('BotUsername'));

            // Try to find the name and map to ChatID
            $NameOrChatID = $this->NameToUserID($NameOrChatID);

            // Send message
            $result = Longman\TelegramBot\Request::sendMessage([
                'chat_id' => $NameOrChatID,
                'text'    => $Text,
            ]);

            if (!$result->isOk()) {
                echo $this->Translate('Sending message failed!');
            }
        } catch (Longman\TelegramBot\Exception\TelegramException $e) {
            echo $e->getMessage();
        }
    }

    public function SendImage(int $MediaID)
    {
        // Send message to everyone
        $recipients = json_decode($this->ReadPropertyString('AllowList'), true);
        foreach ($recipients as $recipient) {
            $this->SendImageEx($MediaID, strval($recipient['UserID']));
        }
    }

    public function SendImageEx(int $MediaID, string $NameOrChatID)
    {
        try {
            $telegram = new Longman\TelegramBot\Telegram($this->ReadPropertyString('BotApiKey'), $this->ReadPropertyString('BotUsername'));

            // Try to find the name and map to ChatID
            $NameOrChatID = $this->NameToUserID($NameOrChatID);

            // Prepare stream (we don't want to do any file I/O)
            $stream = \GuzzleHttp\Psr7\Utils::streamFor(
                base64_decode(IPS_GetMediaContent($MediaID)),
                ['metadata' => ['uri' => basename(IPS_GetMedia($MediaID)['MediaFile'])]]
            );

            // Send message
            $result = Longman\TelegramBot\Request::sendPhoto([
                'chat_id' => $NameOrChatID,
                'caption' => IPS_GetName($MediaID),
                'photo'   => $stream,
            ]);

            if (!$result->isOk()) {
                echo $this->Translate('Sending message failed!');
            }
        } catch (Longman\TelegramBot\Exception\TelegramException $e) {
            echo $e->getMessage();
        }
    }

    /**
     * This function will be called by the hook control. Visibility should be protected!
     */
    protected function ProcessHookData()
    {
        $data = file_get_contents('php://input');
        $this->SendDebug('Event', $data, 0);

        //Parse Event data from JSON
        $data = json_decode($data, true);

        //Check if user has permission to start this action
        $recipients = json_decode($this->ReadPropertyString('AllowList'), true);
        $found = false;
        foreach ($recipients as $recipient) {
            if ($recipient['UserID'] == $data['message']['from']['id']) {
                $found = true;
                break;
            }
        }

        //Notify user that he is not allowed to do that
        if (!$found) {
            $this->SendDebug('SECURITY', sprintf('Access denied to %s %s (%d)', $data['message']['from']['first_name'], $data['message']['from']['last_name'], $data['message']['from']['id']), 0);
            $this->SendMessageEx($this->Translate('Access denied!'), strval($data['message']['from']['id']));
            return;
        }

        //Check if we know the action and can execute it
        $actions = json_decode($this->ReadPropertyString('ActionList'), true);
        $found = false;
        foreach ($actions as $action) {
            if ($action['Command'] == $data['message']['text']) {
                $actionPayload = json_decode($action['Action'], true);

                //Send debug that we will execute
                $this->SendDebug('EXECUTING', sprintf('Action %s is executing by %s %s (%d)', $data['message']['text'], $data['message']['from']['first_name'], $data['message']['from']['last_name'], $data['message']['from']['id']), 0);

                IPS_RunAction($actionPayload['actionID'], $actionPayload['targetID'], $actionPayload['parameters']);

                //Send debug after we executed
                $this->SendDebug('EXECUTED', sprintf('Action %s was executed by %s %s (%d)', $data['message']['text'], $data['message']['from']['first_name'], $data['message']['from']['last_name'], $data['message']['from']['id']), 0);

                //Notify user about our success
                $this->SendMessageEx($this->Translate('Action executed!'), strval($data['message']['from']['id']));
                $found = true;
                break;
            }
        }

        //Notify user that we did not find a suitable action
        if (!$found) {
            $this->SendDebug('UNKNOWN', sprintf('Unknown Action %s was requested by %s %s (%d)', $data['message']['text'], $data['message']['from']['first_name'], $data['message']['from']['last_name'], $data['message']['from']['id']), 0);
            $this->SendMessageEx($this->Translate('Unknown action!'), strval($data['message']['from']['id']));
        }
    }

    private function NameToUserID($NameOrChatID)
    {
        $recipients = json_decode($this->ReadPropertyString('AllowList'), true);
        foreach ($recipients as $recipient) {
            if ($recipient['Name'] == $NameOrChatID) {
                return $recipient['UserID'];
            }
        }
        return $NameOrChatID;
    }
}
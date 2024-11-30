<?php

$config = include('config.php');
$botToken = $config['bot_token'];
$botUsername = $config['bot_username'];
$apiUrl = "https://api.telegram.org/bot{$botToken}/";

function sendRequest($method, $parameters = []) {
    global $apiUrl;
    $url = $apiUrl . $method;
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($parameters),
        ],
    ];
    $context = stream_context_create($options);
    return json_decode(file_get_contents($url, false, $context), true);
}

function getUpdates($offset = null) {
    global $apiUrl;
    $url = $apiUrl . "getUpdates" . ($offset ? "?offset={$offset}" : "");
    $response = file_get_contents($url);
    return json_decode($response, true);
}

function saveUser($chatId, $firstName, $referrerId = null) {
    $data = json_decode(file_get_contents('data.json'), true) ?? [];
    if (!isset($data[$chatId])) {
        $data[$chatId] = [
            'first_name' => $firstName,
            'referrer_id' => $referrerId,
            'balance' => 0,
            'wait_for_ans' => 'no'
        ];
        if ($referrerId && isset($data[$referrerId])) {
            global $config;
            $data[$referrerId]['balance'] += $config['per_reffer_bonus'];
        }
        file_put_contents('data.json', json_encode($data));
    }
}

function checkUserJoinedChannels($chatId) {
    global $config;
    foreach ($config['channels'] as $channel) {
        $response = sendRequest('getChatMember', [
            'chat_id' => $channel,
            'user_id' => $chatId
        ]);
        if (!in_array($response['result']['status'], ['member', 'administrator'])) {
            return false;
        }
    }
    return true;
}

function processWithdrawal($chatId, $upiAddress) {
    $data = json_decode(file_get_contents('data.json'), true);
    if ($data[$chatId]['balance'] >= 10) {
        $amount = $data[$chatId]['balance'];
        $data[$chatId]['balance'] = 0;
        $data[$chatId]['wait_for_ans'] = 'no';
        file_put_contents('data.json', json_encode($data));
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "<b>✅ Hi from FroZzeN_xD! Withdrawal of ₹{$amount} processed to UPI: {$upiAddress}</b>",
            'parse_mode' => 'HTML'
        ]);
    } else {
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "<b>❌ Hi from FroZzeN_xD! Insufficient balance for withdrawal (minimum ₹10).</b>",
            'parse_mode' => 'HTML'
        ]);
    }
}

function sendNormalKeyboard($chatId) {
    $keyboard = [
        [['text' => '💰 Balance'], ['text' => '🔗 Referral']],
        [['text' => '💸 Withdraw']]
    ];
    sendRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => "<b>📋 Main Menu:</b>",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true])
    ]);
}

function processUpdate($update) {
    global $config, $botUsername;

    if (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $firstName = $message['from']['first_name'];
        $text = $message['text'];
        $data = json_decode(file_get_contents('data.json'), true) ?? [];

        if (strpos($text, '/start') === 0) {
            $referrerId = strpos($text, 'ref_') !== false ? str_replace('/start ref_', '', $text) : null;
            saveUser($chatId, $firstName, $referrerId);
            $keyboard = [];
            foreach (array_chunk($config['channels'], 3) as $chunk) {
                $row = [];
                foreach ($chunk as $channel) {
                    $row[] = ['text' => '↗️ Join', 'url' => "https://t.me/" . ltrim($channel, '@')];
                }
                $keyboard[] = $row;
            }
            $keyboard[] = [['text' => '✅ Joined', 'callback_data' => '/joined']];
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "<b>✳️ Welcome to our bot! Please join the channels below.</b>\n\n- Managed by: <a href='https://t.me/FroZzeN_xD'>FroZzeN_xD</a>",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
        } elseif ($text === '💰 Balance') {
            $balance = $data[$chatId]['balance'] ?? 0;
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "<b>💵 Your Balance: ₹{$balance}</b>",
                'parse_mode' => 'HTML'
            ]);
        } elseif ($text === '🔗 Referral') {
            $referralLink = "https://t.me/{$botUsername}?start=ref_{$chatId}";
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "<b>🔗 Referral link:\n{$referralLink}</b>\n\n- Managed by: <a href='https://t.me/FroZzeN_xD'>FroZzeN_xD</a>",
                'parse_mode' => 'HTML'
            ]);
        } elseif ($text === '💸 Withdraw') {
            $data[$chatId]['wait_for_ans'] = 'yes';
            file_put_contents('data.json', json_encode($data));
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "<b>Send your UPI address:</b>\n\n- Managed by: <a href='https://t.me/FroZzeN_xD'>FroZzeN_xD</a>",
                'parse_mode' => 'HTML'
            ]);
        } elseif (isset($data[$chatId]) && $data[$chatId]['wait_for_ans'] === 'yes') {
            processWithdrawal($chatId, $text);
        }
    } elseif (isset($update['callback_query'])) {
        $callbackQuery = $update['callback_query'];
        $chatId = $callbackQuery['from']['id'];
        $data = $callbackQuery['data'];

        if ($data === '/joined') {
            if (checkUserJoinedChannels($chatId)) {
                sendNormalKeyboard($chatId);
            } else {
                sendRequest('answerCallbackQuery', [
                    'callback_query_id' => $callbackQuery['id'],
                    'text' => "Please join all required channels first.",
                    'show_alert' => true
                ]);
            }
        }
    }
}

$lastUpdateId = 0;
while (true) {
    $updates = getUpdates($lastUpdateId + 1);
    if (isset($updates['result'])) {
        foreach ($updates['result'] as $update) {
            $lastUpdateId = $update['update_id'];
            processUpdate($update);
        }
    }
    sleep(1);
}
?>

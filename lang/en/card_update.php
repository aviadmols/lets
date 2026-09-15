<?php

/*
 * "Update your card" — the durable link, its landing page, and the merchant's
 * side of it.
 *
 * The customer-facing copy names the SHOP, never LETS: to the person reading it
 * this is their shop asking, and a message from a name they have never heard of
 * is a message they should ignore.
 */

return [

    // === What the customer sees ===
    'landing' => [
        'title' => 'Update your payment card',
        'heading' => 'Update your payment card',
        'lead' => ':shop needs a current payment card for your subscription.',
        'lead_card' => ':shop needs a current payment card for your subscription. The card on file ends in :last_four.',
        'continue' => 'Update my card',
        'note' => 'You will be taken to a secure payment page. Your card details are never seen by this site.',
        'expires' => 'This link works until :date.',
    ],

    'gone' => [
        'title' => 'This link is no longer active',
        'heading' => 'This link is no longer active',
        // Deliberately the same sentence for every refusal — see the view.
        'body' => 'It may have expired, or already been used. Ask the shop for a new one.',
    ],

    // === What the merchant sees ===
    'action' => [
        'send' => 'Send a card-update link',
        'revoke' => 'Revoke open links',
    ],

    'heading' => 'Send a card-update link',
    'intro' => 'Creates a link that takes this customer to a secure page to enter a new card. The link is theirs alone and you can revoke it at any time.',

    'field' => [
        'channel' => 'How to send it',
        'channel_option' => [
            'copy' => 'Just give me the link',
            'email' => 'Email it',
            'sms' => 'Text it',
        ],
        'channel_help' => [
            'copy' => 'Shown once here, for you to paste wherever you like.',
            'email' => 'Sent to :email from your shop.',
            'sms' => 'Sent to :phone from your shop.',
        ],
        'ttl' => 'The link works for',
        'ttl_help' => 'After that it stops working and you can send a new one.',
        'ttl_option' => [
            '1' => '1 day',
            '3' => '3 days',
            '7' => '1 week',
            '14' => '2 weeks',
            '30' => '30 days',
        ],
    ],

    'state' => [
        'sent' => 'Sent',
        'opened' => 'Opened',
        'completed' => 'Card updated',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
    ],

    'status' => [
        'heading' => 'Card-update links',
        'line' => ':state · :channel · :when',
        'empty' => 'No card-update link has been sent for this subscription.',
        'link_label' => 'The link',
        'durable_hint' => 'Our link. Lasts days, and the PayPlus payment page is created the moment the customer clicks — this is the one to email or message.',
        'direct_label' => 'PayPlus payment page (direct)',
        'direct_hint' => 'Opens the payment page immediately with no hop through us — but it expires on PayPlus\x27s side whether anybody opened it or not. For use now: reading it down the phone, pasting into a chat that will be read in minutes. Not for somebody who will open it tomorrow.',
        'done' => 'Done',
        'copy_hint' => 'Copy it now — it is not shown again.',
    ],

    /*
    | Sharing the link yourself. The message is editable because the merchant
    | knows their customer, and a line written for everybody reads like one.
    */
    'share' => [
        'message_label' => 'The message that will be sent',
        'message_hint' => 'Edit it before sending. The button opens YOUR WhatsApp with this message ready — all that is left is to press send.',
        /*
        | Deliberately no "declined", "failed" or "problem". The customer did
        | nothing wrong, and the first message a shop sends them about money should
        | not read like a telling-off — it should read like a small request. The
        | shop name opens it, because somebody receiving a payment link wants to
        | know who it is from before anything else.
        */
        'default_message' => 'Hi, this is {shop}. To keep your subscription running you can update your card details here — it only takes a moment: {url}',
        'whatsapp' => 'Send on WhatsApp',
        'no_phone' => 'No phone number on file for this customer, so there is no WhatsApp to open. Copy the link and send it another way.',
    ],

    /*
    | The outcome of the last update, at the top of the card section on the
    | subscription screen. Green only when the card really is on the plan — not
    | when somebody opened a page.
    */
    'outcome' => [
        'updated_title' => 'Card updated successfully',
        'updated_body' => 'The customer updated their card on :when. The next charges will use the new card.',
        'updated_body_card' => 'The customer updated their card on :when — a card ending in :last_four. The next charges will use the new card.',
        'not_saved_title' => 'The card update was not saved',
        'not_saved_body' => [
            'no_token' => 'On :when the customer completed the PayPlus page, but PayPlus returned no card to save. The previous card is still on the subscription — check PayPlus or send a new link.',
            'link_revoked' => 'On :when someone completed the payment page from a link you had already revoked. The card was not attached, because a revoked link may be in the wrong person\'s hands.',
        ],
        'failed_title' => 'The card update attempt failed',
        'failed_body' => 'On :when the customer tried to update their card and PayPlus declined it. The previous card is still on the subscription.',
    ],

    'channel' => [
        'copy' => 'copied',
        'email' => 'emailed',
        'sms' => 'texted',
    ],

    'notify' => [
        'created' => 'Link created.',
        'emailed' => 'Link emailed to :to.',
        'texted' => 'Link texted to :to.',
        'revoked' => 'Revoked :count open links.',
        'nothing_revoked' => 'There were no open links to revoke.',
    ],

    'error' => [
        'unavailable' => 'This subscription cannot take a card-update link: the shop is not connected to PayPlus, or the plan has finished.',
        'no_email' => 'No email address on file for this customer.',
        'no_phone' => 'No phone number on file for this customer.',
        'sms_off' => 'SMS is not set up for this shop. Turn it on under Settings → SMS.',
        'send_failed' => 'The link was created but could not be sent. Copy it from the list and send it yourself.',
    ],

    // === The message the customer receives ===
    'message' => [
        'subject' => 'Update your payment card',
        'sms' => 'Hi :name, :business needs a current payment card for your subscription: :url',
    ],

];

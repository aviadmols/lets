<?php

/*
 * EMAIL CAMPAIGNS — the merchant's screen and the shopper's public pages.
 *
 * Key namespaces:
 *   nav.*, model.*        the sidebar entry and the resource's own nouns
 *   section.*, field.*    the form
 *   source.*, status.*    the audience vocabulary and the run's states
 *   recipient_status.*, reason.*   the recipients list
 *   action.*, stat.*, table.*, form.*   actions, counts, columns, notices
 *   login.*, unsubscribe.*, hosted.*    the pages a SHOPPER sees
 *   mail.*                strings that go INSIDE an email
 *
 * The Hebrew mirror (lang/he/campaigns.php) must carry every key, or a merchant
 * — or worse, a customer — reads a raw dotted string.
 */

return [

    'nav' => [
        'label' => 'Campaigns',
    ],

    'model' => [
        'label' => 'campaign',
        'plural' => 'Campaigns',
        'create' => 'New campaign',
        'edit' => 'Edit campaign',
        'empty' => 'No campaigns yet',
        'empty_help' => 'Write an email, choose who gets it, and send it to your subscribers, buyers or club members.',
    ],

    // The screen's three steps: who first, then what they read, then when it goes.
    'step' => [
        'audience' => 'Audience',
        'audience_help' => 'Who the email goes to',
        'design' => 'Design',
        'design_help' => 'What they see in the inbox',
        'send' => 'Schedule & send',
        'send_help' => 'When it goes out',
    ],

    'section' => [
        'basics' => 'The message',
        'basics_help' => 'The name is yours alone — only the subject reaches the customer.',
        'audience' => 'Who gets it',
        'audience_help' => 'Leave a filter empty to mean "anyone". All the filters that are set must match. LETS knows the people it has charged or enrolled — subscribers, deposit buyers and club members — not every order in the store.',
        'content' => 'The email',
        'content_help' => 'Design it here. Use the placeholders below anywhere in the subject or the body — they are filled in for each person as it is sent.',
        'schedule' => 'When it goes',
        'stats' => 'The run',
    ],

    'field' => [
        'name' => 'Campaign name',
        'name_help' => 'For your list only.',
        'subject' => 'Subject',
        'is_marketing' => 'This is a marketing email',
        'is_marketing_help' => 'A marketing email must carry an unsubscribe link, and in Hebrew its subject is prefixed with "פרסומת" — the law requires both. Turn this off only for an operational notice.',
        'emails' => 'Send to specific addresses',
        'emails_placeholder' => 'Paste or type an address and press Enter',
        'emails_help' => 'Leave empty to send by the rules below. Add addresses to send only to them — the rules then do not apply.',
        'emails_active' => 'This campaign goes to those :count addresses only. An address we know also gets their name and a link to their account; one we do not know still gets the email, without those.',
        'rules_muted' => 'The rules below are not in force — this campaign goes only to the addresses you listed above. They are saved as they are; clear the addresses to go back to them.',
        'sources' => 'Who counts as a customer',
        'sources_help' => 'Leave empty for everyone LETS knows.',
        'statuses' => 'Subscription status',
        'statuses_help' => 'Active and paused by default. Pick Cancelled or Completed to reach people whose subscription is over.',
        'frequencies' => 'Billing cycle',
        'frequencies_help' => 'The cycle a subscriber is on TODAY — this is how you reach "yearly members only". Ignored for club members.',
        'products' => 'Products',
        'products_help' => 'Only people whose subscription or purchase is for one of these.',
        'loyalty_tiers' => 'Club tiers',
        'loyalty_tiers_help' => 'Only applies to club members.',
        'editor_mode' => 'Editor',
        'editor_option' => [
            'visual' => 'Visual',
            'html' => 'HTML',
            'studio' => 'Studio (blocks + AI)',
        ],
        'editor_help' => 'The visual editor is simpler; the HTML view gives full control; the studio builds the email from blocks with an AI assistant. The choice is made once, at creation.',
        'studio_pointer' => 'This email is edited in the studio — its body is built there from blocks and updates automatically. Open it with the "Open in studio" button above.',
        'body' => 'Body',
        'body_visual' => 'Body',
        'placeholders' => 'Placeholders',
        'scheduled_at' => 'Send at',
        'scheduled_at_help' => 'Leave empty to send it yourself with the button above.',
        'login_ttl' => 'The sign-in link stays valid for',
        'login_ttl_help' => 'Twice over: the first click must happen within this window of the send, and from that first click the link keeps working for the same window — phone, laptop, wherever. Each link is personal to one person, and "Revoke sign-in links" kills them all at once.',
        'ttl_option' => [
            '24' => '1 day',
            '72' => '3 days',
            '168' => '1 week',
            '336' => '2 weeks',
        ],
    ],

    'source' => [
        'subscribers' => 'Subscribers',
        'subscribers_help' => 'Anyone on a recurring subscription, on either rail.',
        'purchasers' => 'Deposit & instalment buyers',
        'purchasers_help' => 'Anyone who bought through a payment plan — including plans already paid off.',
        'loyalty_members' => 'Club members',
        'loyalty_members_help' => 'Everyone in the loyalty club, subscription or not.',
    ],

    'status' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'cancelled' => 'Cancelled',
    ],

    'recipient_status' => [
        'pending' => 'Waiting',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
    ],

    'reason' => [
        'unsubscribed' => 'Unsubscribed',
        'no_email' => 'No email address',
        'mail_error' => 'The mail server refused it',
        'campaign_cancelled' => 'The campaign was cancelled',
        'shop_not_live' => 'The store was disconnected',
    ],

    'rail' => [
        'payplus' => 'PayPlus',
        'shopify' => 'Shopify',
        'loyalty' => 'Club',
        'manual' => 'Typed address',
    ],

    'action' => [
        'preview' => 'Preview',
        'preview_audience' => 'Preview recipients',
        'send_test' => 'Send a test',
        'send_now' => 'Send now',
        'send_now_confirm' => 'Send this campaign to :count people?',
        'send_now_body' => 'This cannot be undone — an email that has left cannot be recalled. :suppressed of them have unsubscribed and will be skipped.',
        'schedule' => 'Schedule',
        'schedule_confirm' => 'Send it at the time set above?',
        'unschedule' => 'Back to draft',
        'cancel' => 'Cancel campaign',
        'cancel_confirm' => 'Stop this campaign?',
        'cancel_body' => 'Emails already sent stay sent. Everything still waiting is dropped.',
        'retry_failed' => 'Retry the failures',
        'revoke_links' => 'Revoke the sign-in links',
        'revoke_confirm' => 'Revoke every sign-in link in this campaign?',
        'revoke_body' => 'Every link in this email stops working at once, including for people who have not opened it yet. Use this if the campaign reached the wrong list.',
        'duplicate' => 'Duplicate',
        'duplicate_confirm' => 'Start a new draft from this campaign?',
        'duplicate_body' => 'The subject, the content and the audience are copied into a new draft. Nothing is sent, and this campaign is not changed — its recipients, results and sign-in links stay with it.',
    ],

    'stat' => [
        'eligible_now' => 'Would receive it now',
        'eligible_now_help' => 'People matching the audience filters, minus anyone who has unsubscribed.',
        'recipients' => 'Recipients',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
        'status' => 'Status',
        'links_revoked' => 'Sign-in links revoked',
    ],

    'table' => [
        'name' => 'Campaign',
        'status' => 'Status',
        'recipients' => 'Recipients',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'scheduled_at' => 'Scheduled',
        'sent_at' => 'Sent',
        'email' => 'Email',
        'person' => 'Person',
        'rail' => 'From',
        'reason' => 'Reason',
        'account_link' => 'Account link',
    ],

    /*
    | Did this person open their account from the email? Three answers: somebody
    | the campaign never wrote a link to has not failed to click it.
    */
    'account_link' => [
        'clicked' => 'Opened',
        'not_clicked' => 'Not opened',
        'none' => 'No link sent',
        'any' => 'Any',
        'first_of' => ':when · :count visits',
    ],

    'form' => [
        'saved' => 'Campaign saved',
        'unsubscribe_required' => 'A marketing email must contain the :token placeholder — that is the unsubscribe link, and the law requires it.',
        'locked' => 'A campaign that has been sent can no longer be edited.',
        'test_sent' => 'A test was sent to :email.',
        'test_failed' => 'The test could not be sent (:reason).',
        'sent_summary' => 'Sending to :count people. :suppressed skipped, :already already had it.',
        'nothing_to_send' => 'Nobody matches this audience right now.',
        'cannot_send' => 'This campaign cannot be sent in its current state.',
        'scheduled' => 'Scheduled for :time.',
        'cancelled' => 'Campaign cancelled.',
        'retried' => ':count emails were queued again.',
        'links_revoked' => ':count sign-in links were revoked.',
        'send_started' => 'Sending has started.',
        'send_started_body' => 'The audience is being built in the background. The counts on this page fill in as it goes.',
        'duplicated' => 'Copied to “:name”.',
        'duplicate_failed' => 'The campaign could not be copied.',
    ],

    'preview' => [
        'heading' => 'Preview',
        'no_subject' => '(no subject)',
        'desktop' => 'Desktop',
        'mobile' => 'Mobile',
        'note' => 'Sample details. The sign-in and unsubscribe links here are placeholders — a real one is created for each person as the email is sent.',
        'audience_heading' => 'Who would get it',
        'audience_summary' => 'Showing :shown of :total.',
        'already_enrolled' => 'Already had it',
        'unsubscribed' => 'Unsubscribed',
    ],

    /* Strings that go INSIDE an email. */
    'mail' => [
        'ad_prefix' => 'פרסומת: ',
        'friend' => 'there',
        'test_prefix' => '[TEST] ',
    ],

    /* The page behind the sign-in link. Written for a SHOPPER. */
    'login' => [
        'title' => 'Sign in to your account',
        'heading' => 'Welcome back',
        'lead' => 'You are about to sign in to your account at :shop as :email.',
        'continue' => 'Continue to my account',
        'note' => 'This link works once. If you did not ask for it, you can close this page — nothing happens until you press the button.',
        'expired_title' => 'This link is no longer valid',
        'expired_heading' => 'This link has expired',
        'expired_lead' => 'Sign-in links work once and for a limited time. Open your account from the store and sign in there, or ask for a new email.',
        'signed_out' => 'You have been signed out.',
    ],

    /* The SaaS-hosted personal area. */
    'hosted' => [
        'title' => 'My account',
        'signed_in_as' => 'Signed in as :email',
        'logout' => 'Sign out',
    ],

    /* The unsubscribe pages. */
    'unsubscribe' => [
        'title' => 'Unsubscribe',
        'heading' => 'Stop these emails?',
        'lead' => ':shop will stop sending marketing emails to :email. Messages about your subscription itself — receipts, reminders, sign-in codes — keep coming.',
        'confirm' => 'Unsubscribe me',
        'done_title' => 'Unsubscribed',
        'done_heading' => 'Done',
        'done_lead' => 'You will not receive marketing emails from :shop again.',
    ],

    /*
    | Appended to a copied campaign's name. Numbered ("(copy) 2") when a copy by
    | that name already exists — two rows with one name, where one is sent and
    | one is a draft, is how the wrong one gets sent.
    */
    'duplicate' => [
        'suffix' => '(copy)',
    ],

];

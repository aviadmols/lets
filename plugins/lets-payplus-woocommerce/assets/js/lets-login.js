/**
 * LETS — the sign-in screen's behaviour.
 *
 * THREE STEPS, ONE AT A TIME. Destination → code → (only when the verified
 * address belongs to nobody) name. Each step REPLACES the one before it. The
 * earlier panel revealed the code box under the phone field and left both on
 * screen, which asked the shopper to work out which half was still live; a code
 * screen that shows the number it was sent to, a way back and a way to resend is
 * a smaller thing to read and a smaller thing to get wrong.
 *
 * WHAT THIS FILE MAY NOT DO. It never states who the shopper is. It posts a
 * destination and a code to the plugin's own REST routes (nonce-guarded), and
 * the plugin — server-side, over HMAC — asks LETS whether the code matched and
 * then decides, in WordPress, whose session to issue. The browser holds no
 * secret, and "I am this user" is not a sentence it can say.
 *
 * The registration TICKET is the same idea: the server hands back an opaque
 * string standing for a destination it verified, and this file passes it back
 * untouched. It never learns, and never sends, the address the ticket stands for.
 */
(function () {
    'use strict';

    // === CONSTANTS ===

    /** Digits in a code — the length that auto-submits. */
    var CODE_LENGTH = 6;

    /** A phone number has to be at least this many digits to be worth sending to. */
    var PHONE_MIN = 9;
    var PHONE_MAX = 15;

    /** Fallback wait before "send again" is offered, when the config is silent. */
    var RESEND_DEFAULT = 30;

    /** The mask character. Not an asterisk: this reads as redaction, not as "required". */
    var DOT = '•';

    /**
     * Every panel on the page is wired by the FIRST copy of this script. There is
     * normally one, but a theme can print two login forms, and each would bring
     * its own enqueue.
     */
    if (window.LetsLoginWired) { return; }
    window.LetsLoginWired = true;

    var cfg = window.LetsLoginCfg || {};
    var S = cfg.strings || {};
    var RESEND = parseInt(cfg.resend, 10) > 0 ? parseInt(cfg.resend, 10) : RESEND_DEFAULT;

    /* ---------------------------------------------------------------------
     * Transport
     * ------------------------------------------------------------------ */

    /**
     * A POST that tells success from failure. Parsing the body and shrugging at
     * the status is how a 403 from a cached-page nonce once read exactly like a
     * delivered code.
     */
    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify(body)
        }).then(function (response) {
            if (!response.ok) { throw new Error('http ' + response.status); }

            return response.json();
        });
    }

    /* ---------------------------------------------------------------------
     * Masking — what the code step says it sent to
     *
     * The shopper typed it, so this is not secrecy; it is confirmation. Enough
     * to recognise your own number at a glance and notice a typo, and not the
     * whole address printed on a screen somebody else may be looking at.
     * ------------------------------------------------------------------ */

    function maskPhone(value) {
        var digits = String(value).replace(/\D+/g, '');
        if (digits.length <= 4) { return digits; }

        var tail = digits.slice(-4);

        return digits.length >= PHONE_MIN
            ? DOT + DOT + DOT + '-' + DOT + DOT + DOT + '-' + tail
            : DOT + DOT + DOT + tail;
    }

    function maskEmail(value) {
        var address = String(value);
        var at = address.lastIndexOf('@');
        if (at < 1) { return address; }

        return address.charAt(0) + DOT + DOT + DOT + address.slice(at);
    }

    function mask(channel, value) {
        return channel === 'sms' ? maskPhone(value) : maskEmail(value);
    }

    /* ---------------------------------------------------------------------
     * Local shape checks
     *
     * NOT security — the server checks everything again, and it is the server
     * that decides what a code costs. This only spares the shopper a round trip
     * to be told their number is missing a digit.
     * ------------------------------------------------------------------ */

    function looksLikePhone(value) {
        var digits = String(value).replace(/\D+/g, '').length;

        return digits >= PHONE_MIN && digits <= PHONE_MAX;
    }

    function looksLikeEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(value).trim());
    }

    /* ---------------------------------------------------------------------
     * One panel
     * ------------------------------------------------------------------ */

    function wire(root) {
        var channel = root.getAttribute('data-lets-initial-channel') === 'sms' ? 'sms' : 'email';
        var steps = {};
        var current = 'dest';
        var destination = '';
        var ticket = '';
        var countdown = null;

        /**
         * One call in flight at a time.
         *
         * The buttons disable themselves, but the code field submits ITSELF on
         * the sixth digit and Enter runs the same action — so a paste, or a
         * keystroke landing while the first request travels, could fire a second
         * verify. Each one spends a guess out of the five the code is allowed,
         * which is how a shopper with the RIGHT code gets told it is exhausted.
         */
        var pending = false;

        Array.prototype.forEach.call(root.querySelectorAll('[data-lets-step]'), function (node) {
            steps[node.getAttribute('data-lets-step')] = node;
        });

        var dest = root.querySelector('[data-lets-dest]');
        var destLabel = root.querySelector('[data-lets-dest-label]');
        var sendBtn = root.querySelector('[data-lets-send]');
        var codeInput = root.querySelector('[data-lets-code-input]');
        var verifyBtn = root.querySelector('[data-lets-verify]');
        var sentLine = root.querySelector('[data-lets-sent]');
        var resendBtn = root.querySelector('[data-lets-resend]');
        var firstName = root.querySelector('[data-lets-first]');
        var lastName = root.querySelector('[data-lets-last]');
        var emailField = root.querySelector('[data-lets-email]');
        var phoneField = root.querySelector('[data-lets-phone]');
        var phoneLabel = root.querySelector('[data-lets-phone-label]');
        var emailLabel = root.querySelector('[data-lets-email-label]');
        var createBtn = root.querySelector('[data-lets-create]');

        /* --- steps ------------------------------------------------------ */

        function show(name) {
            if (!steps[name]) { return; }

            Object.keys(steps).forEach(function (key) {
                steps[key].hidden = key !== name;
            });
            current = name;
            clearMessage();
        }

        function messageNode() {
            return steps[current] ? steps[current].querySelector('[data-lets-msg]') : null;
        }

        function clearMessage() {
            var node = messageNode();
            if (!node) { return; }
            node.textContent = '';
            node.removeAttribute('data-tone');
        }

        /** Inline text under the field it is about. `tone` is 'bad' unless said. */
        function say(key, tone) {
            var node = messageNode();
            if (!node) { return; }

            node.textContent = S[key] || S.rejected || '';
            node.setAttribute('data-tone', tone || 'bad');
        }

        function busy(button, key) {
            pending = true;

            if (!button) {
                return function () { pending = false; };
            }

            var label = button.textContent;
            button.disabled = true;
            if (key && S[key]) { button.textContent = S[key]; }

            return function () {
                pending = false;
                button.disabled = false;
                button.textContent = label;
            };
        }

        /** Land back where the shopper was; with nowhere to go, reload as them. */
        function here() { return window.location.href; }

        function land(url) {
            if (url) {
                window.location.href = url;

                return;
            }
            window.location.reload();
        }

        /* --- step 1: the destination ------------------------------------ */

        var segments = root.querySelectorAll('[data-lets-channel]');

        function selectChannel(next) {
            channel = next === 'sms' ? 'sms' : 'email';

            Array.prototype.forEach.call(segments, function (segment) {
                var active = segment.getAttribute('data-lets-channel') === channel;
                segment.classList.toggle('is-active', active);
                segment.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            if (destLabel) { destLabel.textContent = channel === 'sms' ? S.phone : S.email; }
            if (dest) {
                dest.setAttribute('inputmode', channel === 'sms' ? 'tel' : 'email');
                dest.setAttribute('autocomplete', channel === 'sms' ? 'tel' : 'email');
                dest.classList.toggle('la-ltr', channel === 'sms');
            }
            clearMessage();
        }

        Array.prototype.forEach.call(segments, function (segment) {
            segment.addEventListener('click', function () {
                selectChannel(segment.getAttribute('data-lets-channel'));
                if (dest) { dest.focus(); }
            });
        });

        function send(again) {
            if (pending || !dest) { return; }

            var typed = dest.value.trim();
            if (channel === 'sms' ? !looksLikePhone(typed) : !looksLikeEmail(typed)) {
                show('dest');
                say(channel === 'sms' ? 'bad_phone' : 'bad_email');
                dest.focus();

                return;
            }

            destination = typed;

            var release = busy(again ? resendBtn : sendBtn, 'sending');

            post(cfg.request, { channel: channel, destination: destination }).then(function () {
                release();
                openCode(again);
            }).catch(function () {
                release();
                say('unreachable');
            });
        }

        /* --- step 2: the code ------------------------------------------- */

        function openCode(again) {
            show('code');

            if (sentLine) {
                var parts = String(S.sent_to || '%s').split('%s');
                sentLine.textContent = '';
                sentLine.appendChild(document.createTextNode(parts[0]));

                // ALWAYS isolated left-to-right. A masked number or address is a
                // run of Latin digits and neutral punctuation, and dropped bare
                // into a Hebrew sentence the bidi algorithm reorders it — the
                // shopper checks the number against their handset and sees a
                // different one.
                var marked = document.createElement('span');
                marked.className = 'la-auth__dest la-ltr';
                marked.textContent = mask(channel, destination);
                sentLine.appendChild(marked);

                if (parts.length > 1) {
                    sentLine.appendChild(document.createTextNode(parts[1]));
                }
            }

            if (codeInput) {
                codeInput.value = '';
                codeInput.focus();
            }

            if (again) { say('resent', 'ok'); }

            startCountdown();
        }

        function startCountdown() {
            if (!resendBtn) { return; }

            var left = RESEND;

            if (countdown) { window.clearInterval(countdown); }

            function tick() {
                if (left <= 0) {
                    window.clearInterval(countdown);
                    countdown = null;
                    resendBtn.disabled = false;
                    resendBtn.textContent = S.resend || '';

                    return;
                }

                resendBtn.textContent = String(S.resend_in || '%s').replace('%s', String(left));
                left--;
            }

            resendBtn.disabled = true;
            tick();
            countdown = window.setInterval(tick, 1000);
        }

        function stopCountdown() {
            if (!countdown) { return; }
            window.clearInterval(countdown);
            countdown = null;
            if (resendBtn) {
                resendBtn.disabled = false;
                resendBtn.textContent = S.resend || '';
            }
        }

        function verify() {
            if (pending || !codeInput) { return; }

            var code = codeInput.value.replace(/\D+/g, '');
            if (code.length !== CODE_LENGTH) {
                say('code_short');

                return;
            }

            var release = busy(verifyBtn, 'checking');

            post(cfg.verify, {
                channel: channel,
                destination: destination,
                code: code,
                redirect: here()
            }).then(function (body) {
                release();

                if (!body || !body.ok) {
                    say((body && body.reason) || 'rejected');

                    return;
                }

                // The address answered but belongs to nobody: finish the sign-up
                // rather than sign in. `ok` is never read as a session on its own.
                if (body.register && body.ticket) {
                    ticket = String(body.ticket);
                    stopCountdown();
                    openRegister();

                    return;
                }

                // LETS recognized them and their account was just opened from
                // what it knows — say so for a beat before landing, so the jump
                // straight past the registration form reads as intended, not
                // as a glitch.
                if (body.created) {
                    say('provisioned', 'good');
                    stopCountdown();
                    window.setTimeout(function () { land(body.redirect); }, 900);

                    return;
                }

                land(body.redirect);
            }).catch(function () {
                release();
                say('unreachable');
            });
        }

        /* --- step 3: quick registration --------------------------------- */

        function openRegister() {
            show('register');

            // The VERIFIED half is fixed and shown as read-only — it is the thing
            // the shopper just proved, and it is not theirs to edit here. The
            // server ignores it either way and reads the ticket instead.
            if (channel === 'email') {
                if (emailField) {
                    emailField.value = destination;
                    emailField.readOnly = true;
                }
                if (phoneField) {
                    phoneField.readOnly = false;
                    phoneField.value = '';
                }
                if (emailLabel) { emailLabel.textContent = S.email_verified || S.email; }
                if (phoneLabel) { phoneLabel.textContent = S.phone_optional || S.phone; }
            } else {
                if (phoneField) {
                    phoneField.value = destination;
                    phoneField.readOnly = true;
                }
                if (emailField) {
                    emailField.readOnly = false;
                    emailField.value = '';
                }
                if (emailLabel) { emailLabel.textContent = S.email; }
                if (phoneLabel) { phoneLabel.textContent = S.phone_verified || S.phone; }
            }

            if (firstName) { firstName.focus(); }
        }

        function createAccount() {
            if (pending || !firstName || !lastName) { return; }

            var first = firstName.value.trim();
            var last = lastName.value.trim();
            var email = emailField ? emailField.value.trim() : '';
            var phone = phoneField ? phoneField.value.trim() : '';

            if (!first || !last) {
                say('name_required');
                firstName.focus();

                return;
            }

            if (channel !== 'email' && !looksLikeEmail(email)) {
                say('email_invalid');
                if (emailField) { emailField.focus(); }

                return;
            }

            var release = busy(createBtn, 'creating');

            post(cfg.register, {
                ticket: ticket,
                first_name: first,
                last_name: last,
                email: email,
                phone: phone,
                redirect: here()
            }).then(function (body) {
                release();

                if (body && body.ok) {
                    land(body.redirect);

                    return;
                }

                var reason = (body && body.reason) || 'rejected';

                if ('email_taken' === reason) {
                    sayEmailTaken(email);

                    return;
                }

                // The ticket is single-use and ten minutes old at most. Once it is
                // gone there is nothing on this step that can succeed, so the
                // shopper is put back at the beginning rather than left pressing
                // a button that will keep saying no.
                if ('ticket_expired' === reason) {
                    ticket = '';
                    show('dest');
                    say('ticket_expired');
                    if (dest) { dest.focus(); }

                    return;
                }

                say(reason);
            }).catch(function () {
                release();
                say('unreachable');
            });
        }

        /**
         * "That address already has an account." The only useful next move is to
         * sign in WITH it, so the message carries the door rather than describing
         * it — back to step one, on the email channel, with the address already in.
         */
        function sayEmailTaken(email) {
            var node = messageNode();
            if (!node) { return; }

            node.textContent = S.email_taken || '';
            node.setAttribute('data-tone', 'bad');

            var link = document.createElement('button');
            link.type = 'button';
            link.className = 'la-auth__link';
            link.textContent = S.email_taken_cta || '';
            link.addEventListener('click', function () {
                ticket = '';
                var canSwitch = root.querySelector('[data-lets-channel="email"]');
                if (canSwitch) { selectChannel('email'); }
                show('dest');
                if (dest && canSwitch) { dest.value = email; }
                if (dest) { dest.focus(); }
            });

            node.appendChild(link);
        }

        /* --- wiring ------------------------------------------------------ */

        if (sendBtn) {
            sendBtn.addEventListener('click', function () { send(false); });
        }
        if (verifyBtn) {
            verifyBtn.addEventListener('click', verify);
        }
        if (resendBtn) {
            resendBtn.addEventListener('click', function () { send(true); });
        }
        if (createBtn) {
            createBtn.addEventListener('click', createAccount);
        }

        Array.prototype.forEach.call(root.querySelectorAll('[data-lets-back]'), function (button) {
            button.addEventListener('click', function () {
                ticket = '';
                stopCountdown();
                show('dest');
                if (dest) { dest.focus(); }
            });
        });

        // Digits only, and the sixth one submits: a code screen where you type
        // six characters and then hunt for a button is a screen with a step too
        // many. The button stays for keyboards and screen readers.
        if (codeInput) {
            codeInput.addEventListener('input', function () {
                var digits = codeInput.value.replace(/\D+/g, '').slice(0, CODE_LENGTH);
                if (digits !== codeInput.value) { codeInput.value = digits; }

                if (digits.length === CODE_LENGTH) { verify(); }
            });
        }

        /**
         * Enter runs the step's own action.
         *
         * On checkout this panel sits INSIDE WooCommerce's login <form>, so an
         * un-prevented Enter submits that form with an empty username and answers
         * a shopper asking for a code with "Username is required".
         */
        function enterRuns(field, action) {
            if (!field) { return; }

            field.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.keyCode !== 13) { return; }
                event.preventDefault();
                action();
            });
        }

        enterRuns(dest, function () { send(false); });
        enterRuns(codeInput, verify);
        enterRuns(firstName, createAccount);
        enterRuns(lastName, createAccount);
        enterRuns(emailField, createAccount);
        enterRuns(phoneField, createAccount);

        wireClassicToggle(root);
        wireGoogle(root, say, land);
    }

    /* ---------------------------------------------------------------------
     * The password form, one click away
     * ------------------------------------------------------------------ */

    function wireClassicToggle(root) {
        var toggle = root.querySelector('[data-lets-classic]');
        var classic = document.getElementById('lets-login-classic');
        if (!toggle || !classic) { return; }

        // WooCommerce printed an error, so the form is already open: the label has
        // to agree with what the shopper can see.
        if (!classic.hidden) {
            toggle.setAttribute('aria-expanded', 'true');
            toggle.textContent = S.password_hide || toggle.textContent;
        }

        toggle.addEventListener('click', function () {
            var opening = classic.hidden;
            classic.hidden = !opening;
            toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
            toggle.textContent = (opening ? S.password_hide : S.password_show) || toggle.textContent;

            if (!opening) { return; }

            var username = classic.querySelector('#username, input[name="username"]');
            if (username) { username.focus(); }
        });
    }

    /* ---------------------------------------------------------------------
     * Sign in with Google — step one only
     * ------------------------------------------------------------------ */

    function wireGoogle(root, say, land) {
        var box = root.querySelector('[data-lets-google]');
        if (!box) { return; }

        withGoogle(function () {
            window.google.accounts.id.initialize({
                client_id: box.getAttribute('data-client-id'),
                ux_mode: 'popup',
                callback: function (response) {
                    if (!response || !response.credential) { return; }

                    post(cfg.google, { credential: response.credential, redirect: window.location.href })
                        .then(function (body) {
                            if (body && body.ok) {
                                land(body.redirect);

                                return;
                            }
                            say(body && body.reason === 'no_account' ? 'no_account' : 'google_error');
                        })
                        .catch(function () {
                            say('google_error');
                        });
                }
            });

            window.google.accounts.id.renderButton(box, {
                type: 'standard',
                theme: 'outline',
                size: 'large',
                text: 'signin_with',
                shape: 'pill',
                logo_alignment: 'center',
                locale: cfg.locale,
                width: 320
            });
        });
    }

    /**
     * Google's script, fetched once for the whole page and only when a merchant
     * configured a client id — a shop without one pays nothing on its login page.
     */
    var googleWaiting = [];

    function withGoogle(callback) {
        if (window.google && window.google.accounts && window.google.accounts.id) {
            callback();

            return;
        }

        googleWaiting.push(callback);
        if (googleWaiting.length > 1) { return; }

        var script = document.createElement('script');
        script.src = 'https://accounts.google.com/gsi/client';
        script.async = true;
        script.defer = true;
        script.onload = function () {
            if (!window.google || !window.google.accounts || !window.google.accounts.id) { return; }
            googleWaiting.forEach(function (waiting) { waiting(); });
            googleWaiting = [];
        };
        document.head.appendChild(script);
    }

    /* ------------------------------------------------------------------ */

    function wireAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-lets-login]'), wire);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireAll);
    } else {
        wireAll();
    }
}());

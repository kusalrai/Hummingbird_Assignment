// Copyright © Stripe, Inc
//
// @package    StripeIntegration_Payments
// @version    3.3.13

var initStripe = function(params, callback)
{
    if (typeof callback == "undefined")
        callback = null;

    require(['stripejs', 'domReady!', 'mage/mage', 'mage/url', 'mage/storage'], function(stripejs, domReady, mage, urlBuilder, storage)
    {
        stripe.urlBuilder = urlBuilder;
        stripe.storage = storage;
        stripe.initStripeJs(params, callback);
    });
};

// Global Namespace
var stripe =
{
    // Properties
    version: "3.3.13",
    quote: null, // Comes from the checkout js
    customer: null, // Comes from the checkout js
    card: null,
    stripeJs: null,
    apiKey: null,
    token: null,
    sourceId: null,
    response: null,
    paymentIntent: null,
    paymentIntents: [],
    concludedPaymentIntents: [],
    isAdmin: false,
    urlBuilder: null,
    storage: null,
    prButton: null,
    adminSourceOwner: null,
    PRAPIEvent: null,
    paymentRequest: null,
    cardElement: null,

    // Methods
    initStripeJs: function(params, callback)
    {
        var message = null;

        if (!stripe.stripeJs)
        {
            try
            {
                stripe.stripeJs = Stripe(params.apiKey);
            }
            catch (e)
            {
                if (typeof e != "undefined" && typeof e.message != "undefined")
                    message = 'Could not initialize Stripe.js: ' + e.message;
                else
                    message = 'Could not initialize Stripe.js';
            }

            if (stripe.stripeJs && typeof params.appInfo != "undefined")
            {
                try
                {
                    stripe.stripeJs.registerAppInfo(params.appInfo);
                }
                catch (e)
                {
                    console.warn(e);
                }
            }
        }

        if (callback)
            callback(message);
        else if (message)
            console.error(message);
    },

    onWindowLoaded: function(callback)
    {
        if (window.attachEvent)
            window.attachEvent("onload", callback); // IE
        else
            window.addEventListener("load", callback); // Other browsers
    },

    disableCardValidation: function()
    {
        // Disable server side card validation
        if (typeof AdminOrder != 'undefined' && AdminOrder.prototype.loadArea && typeof AdminOrder.prototype._loadArea == 'undefined')
        {
            AdminOrder.prototype._loadArea = AdminOrder.prototype.loadArea;
            AdminOrder.prototype.loadArea = function(area, indicator, params)
            {
              if (typeof area == "object" && area.indexOf('card_validation') >= 0)
                area = area.splice(area.indexOf('card_validation'), 0);

              if (area.length > 0)
                return this._loadArea(area, indicator, params);
            };
        }
    },

    editSubscription: function(subscriptionId, section)
    {
        jQuery('.stripe-subscription-edit .mutable.section', 'tr.'+subscriptionId).hide();
        jQuery('.stripe-subscription-edit.'+section+'.'+subscriptionId+' .mutable.section', 'tr.'+subscriptionId).show();
        jQuery('.stripe-subscription-edit.'+subscriptionId+' .static.section', 'tr.'+subscriptionId).hide();
    },

    cancelEditSubscription: function(subscriptionId)
    {
        jQuery('.stripe-subscription-edit.'+subscriptionId+' .mutable.section', 'tr.'+subscriptionId).hide();
        jQuery('.stripe-subscription-edit.'+subscriptionId+' .static.section', 'tr.'+subscriptionId).show();
    },

    hasClass: function(element, className)
    {
        return (' ' + element.className + ' ').indexOf(' ' + className + ' ') > -1;
    },

    removeClass: function (element, className)
    {
        if (element.classList)
            element.classList.remove(className);
        else
        {
            var classes = element.className.split(" ");
            classes.splice(classes.indexOf(className), 1);
            element.className = classes.join(" ");
        }
    },

    addClass: function (element, className)
    {
        if (element.classList)
            element.classList.add(className);
        else
            element.className += (' ' + className);
    },

    maskError: function(err)
    {
        var errLowercase = err.toLowerCase();
        var pos1 = errLowercase.indexOf("Invalid API key provided".toLowerCase());
        var pos2 = errLowercase.indexOf("No API key provided".toLowerCase());
        if (pos1 === 0 || pos2 === 0)
            return 'Invalid Stripe API key provided.';

        return err;
    },
    handleCardPayment: function(paymentIntent, done)
    {
        try
        {
            stripe.closePaysheet('success');

            stripe.stripeJs.handleCardPayment(paymentIntent.client_secret).then(function(result)
            {
                if (result.error)
                    return done(result.error.message);

                return done();
            });
        }
        catch (e)
        {
            done(e.message);
        }
    },
    handleCardAction: function(paymentIntent, done)
    {
        try
        {
            stripe.closePaysheet('success');

            stripe.stripeJs.handleCardAction(paymentIntent.client_secret).then(function(result)
            {
                if (result.error)
                    return done(result.error.message);

                return done();
            });
        }
        catch (e)
        {
            done(e.message);
        }
    },
    processNextAuthentication: function(done)
    {
        if (stripe.paymentIntents.length > 0)
        {
            stripe.paymentIntent = stripe.paymentIntents.pop();
            stripe.authenticateCustomer(stripe.paymentIntent, function(err)
            {
                if (err)
                    done(err);
                else
                    stripe.processNextAuthentication(done);
            });
        }
        else
        {
            stripe.paymentIntent = null;
            return done();
        }
    },
    authenticateCustomer: function(paymentIntentId, done)
    {
        try
        {
            stripe.stripeJs.retrievePaymentIntent(paymentIntentId).then(function(result)
            {
                if (result.error)
                    return done(result.error);

                if (result.paymentIntent.status == "requires_action" ||
                    result.paymentIntent.status == "requires_source_action")
                {
                    if (result.paymentIntent.confirmation_method == "manual")
                        return stripe.handleCardAction(result.paymentIntent, done);
                    else
                        return stripe.handleCardPayment(result.paymentIntent, done);
                }

                return done();
            });
        }
        catch (e)
        {
            done(e.message);
        }
    },
    isNextAction3DSecureRedirect: function(result)
    {
        if (!result)
            return false;

        if (typeof result.paymentIntent == 'undefined' || !result.paymentIntent)
            return false;

        if (typeof result.paymentIntent.next_action == 'undefined' || !result.paymentIntent.next_action)
            return false;

        if (typeof result.paymentIntent.next_action.use_stripe_sdk == 'undefined' || !result.paymentIntent.next_action.use_stripe_sdk)
            return false;

        if (typeof result.paymentIntent.next_action.use_stripe_sdk.type == 'undefined' || !result.paymentIntent.next_action.use_stripe_sdk.type)
            return false;

        return (result.paymentIntent.next_action.use_stripe_sdk.type == 'three_d_secure_redirect');
    },
    paymentIntentCanBeConfirmed: function()
    {
        // If stripe.sourceId exists, it means that we are using a saved card source, which is not going to be a 3DS card
        // (because those are hidden from the admin saved cards section)
        return !stripe.sourceId;
    },

    // Converts tokens in the form "src_1E8UX32WmagXEVq4SpUlSuoa:Visa:4242" into src_1E8UX32WmagXEVq4SpUlSuoa
    cleanToken: function(token)
    {
        if (typeof token == "undefined" || !token)
            return null;

        if (token.indexOf(":") >= 0)
            return token.substring(0, token.indexOf(":"));

        return token;
    },
    closePaysheet: function(withResult)
    {
        try
        {
            if (stripe.PRAPIEvent)
                stripe.PRAPIEvent.complete(withResult);
            else if (stripe.paymentRequest)
                stripe.paymentRequest.abort();
        }
        catch (e)
        {
            // Will get here if we already closed it
        }
    },
    isAuthenticationRequired: function(msg)
    {
        stripe.paymentIntent = null;

        // 500 server side errors
        if (typeof msg == "undefined")
            return false;

        // Case of subscriptions
        if (msg.indexOf("Authentication Required: ") >= 0)
        {
            stripe.paymentIntents = msg.substring("Authentication Required: ".length).split(",");
            return true;
        }

        return false;
    }
};

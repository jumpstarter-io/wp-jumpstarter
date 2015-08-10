
jQuery(document).ready(function() {
    var loginHref = jQuery("#js-login a:first").attr("href");
    var insecureDomain = jsGetParam("insecure-domain");
    if (loginHref !== "#" && !insecureDomain)
        return;
    jQuery("#js-insecure-domain").detach().insertBefore("#loginform");
    jQuery("#js-login a:first").click(function(ev) {
        ev.preventDefault();
        jQuery("#js-insecure-domain").show();
        return false;
    });
    if (insecureDomain) {
        jQuery("#js-insecure-domain").show();
    }
});

var $ = jQuery;

$(function() {
    $('body')
        .children()
        .each(function() {
            $(this).html($(this).html().replaceAll('NT', '新界'));
            $(this).html($(this).html().replaceAll('HK', '香港'));
            $(this).html($(this).html().replaceAll('KL', '九龍'));

            // $(this).html($(this).html().replaceAll('NT', '新界'));
        });
});
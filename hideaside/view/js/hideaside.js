$(document).ready(function() {
	var hideaside_timer = null;

	$(document).on('hz:updateConvItems hz:updatePageItems', function(event) {
		if(typeof bParam_page !== 'undefined' && $(window).width() > 992 && bParam_page > 2) {
			if(hideaside_timer)
				clearTimeout(hideaside_timer);
			hideaside_timer = setTimeout(function(){$('aside').animate({ opacity: 0 }, 3000);}, 2000);
		}
	});

	$('aside, aside *').on('mouseover', function(){
		clearTimeout(hideaside_timer);
		if(typeof bParam_page !== 'undefined' && $(window).width() > 992 && bParam_page > 2)
			$('aside').animate({ opacity: 1 });
	});

	$('aside').on('mouseleave', function(){
		if(typeof bParam_page !== 'undefined' && $(window).width() > 992 && bParam_page > 2) {
			if(hideaside_timer)
				clearTimeout(hideaside_timer);
			hideaside_timer = setTimeout(function(){$('aside').animate({ opacity: 0 }, 3000);}, 7000);
		}
	});
});

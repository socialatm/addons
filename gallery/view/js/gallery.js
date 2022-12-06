$(document).ready(function() {

	var selector = '.wall-item-body img, .wall-photo-item img';
	var imgMinSize = 300;

	$(document).on('click', selector, function(e) {
		if(e.target.naturalWidth < imgMinSize && e.target.naturalHeight < imgMinSize)
			return;

		e.preventDefault();
		e.stopPropagation();

		var items = [];
		var startImage;
		var id;

		if($(e.target).closest('.wall-photo-item').length)
			id = $(e.target).closest('.wall-photo-item').attr('id');

		if($(e.target).closest('.reshared-content').length)
			id = $(e.target).closest('.reshared-content').attr('id');
		
		if(! id)
			id = $(e.target).closest('.wall-item-body').attr('id');

		var img = $('#' + id).find('img');

		img.each( function (index, item) {
			if(item.naturalWidth < imgMinSize && item.naturalHeight < imgMinSize)
				return;

			if(item.src == e.target.src)
				startImage = index;

			if(item.parentElement.tagName == 'A')
				var link = decodeURIComponent(item.parentElement.href);

			obj = {
				src: item.src,
				msrc: item.src,
				w: item.naturalWidth,
				h: item.naturalHeight,
				title: link ? '<center><b>This image is linked:</b> click <a href="' + link + '" target="_blank"><b>here</b></a> to follow the link!</center>' : ''
			};

			items.push(obj);

		});

		if(! items.length)
			return;

		var options = {
			index: startImage, // start at first slide
			shareEl: false
		};

		var pswpElement = document.querySelectorAll('.pswp')[0];
		gallery = new PhotoSwipe(pswpElement, PhotoSwipeUI_Default, items, options);
		gallery.init();
	});

	$(document).on('mouseenter', selector, function(e) {
		if(e.target.naturalWidth < imgMinSize && e.target.naturalHeight < imgMinSize)
			return;

		$(this).css('cursor', 'zoom-in');
	});

});



function workflowOffsiteIFrame( posturl, action, miscdata, id ) {

	var postdata = {
			action: action,
			jsondata: miscdata,
			parentwindowid: $(document).mywindowid
	};
	$.ajax({
		type: 'POST',
		url: posturl,
		data: postdata,
		dataType: 'json',
		success: function (data) {
			if (data.html) {
				$(id).html(data.html);
				if (id == '#workflowModal') {
					$('#workflowModal').modal('show');
				} else {
					$(id).show();
				}
			}},
		error: function (req, status, err) {
			alert('There was an error processing the request.' + status + err);
		}
		});
	}

function workflowShowNewItemForm( linkurl, posturl ) {
	var postdata = {
			action: 'getmodal_getiframe',
			jsondata: { linkeditem: linkurl }
	};

	$.ajax({
		type: 'POST',
		url: posturl,
		data: postdata,
		dataType: 'json',
		success: function (data) {
			if (data.html) {
				$('#workflowModal').html(data.html);
				$('#workflowModal').modal('show');
			}},
		error: function (req, status, err) {
			alert('There was an error processing the request.' + status + err);
		}
		});
	}


function workflowSubmitWorkflowUrl(posturl) {
	var fd = $('#workflowWorkflowList').wfserializeObject();
	var formdata = $('#workflowWorkflowList');
	var postdata = {
		action: 'getmodal_getiframe',
		iframeurl: $('#workflowiframeURL').val(),
		jsondata: $('#workflowWorkflowList').wfserializeObject()
		};

	if (typeof wforiginurl !== 'undefined') {
		window.parent.postMessage(JSON.stringify({parentwindowid: parentwindowid, message: 'wfitemsubmitted'}),wforiginurl);
	}
	console.log("POSTDATA:"+JSON.stringify(postdata));
	console.log("POSTURL: "+posturl);
	$.ajax({
		type: 'POST',
		url: posturl,
		data: postdata,
		dataType: 'json',
		success: function (data) {
			if (data.html) {
				$('#workflow-modalcontent').html(data.html);
				if (typeof wforiginurl !== 'undefined') {
					window.parent.postMessage(JSON.stringify({parentwindowid: parentwindowid, message: 'wfitemreturned'}),wforiginurl);
				}
				$('#workflow-spinner').hide();
			} else {
				if (typeof wforiginurl !== 'undefined') {
					window.parent.postMessage(JSON.stringify({parentwindowid: parentwindowid, message: 'wfclosemodal'}),wforiginurl);
				}
			}},
		error: function (req, status, err) {
			alert('There was an error processing the request.' + status + err);
			if (typeof wforiginurl !== 'undefined') {
				window.parent.postMessage(JSON.stringify({parentwindowid: parentwindowid, message: 'wfitemdatareturned'}),wforiginurl);
			}
			$('#workflowSubmit').show();
		}
	});
}

window.addEventListener("message",function(event) {

	try {
		msginfo = JSON.parse(event.data);
	} catch (e) {
		return;
	}

	if (msginfo.parentwindowid != windowid) {
		return;
	}
	if (msginfo.message=='wfclosemodal') {
		$('#workflowModal').modal('hide');
		workflowOffsiteIFrame( 
			itemPostURL,
			'reload_wfitem',
			JSON.stringify({itemid: wfitemid,action: 'reload_wfitem',uuid: uuid,mid: mid}),
			'#wfitemdata' );
	}
	if (msginfo.message=='wfitemsubmitted') {
	}
	if (msginfo.message=='wfitemdatareturned') {
	}
});



window.workflowCloseModal = function() {
	$('#workflowModal').modal('hide');
}



$(document).ready(function() {
	$(document).on("click","#workflowSubmitWorkflowUrl",function () {
		$('#workflowSubmitWorkflowUrl').hide();
		workflowSubmitWorkflowUrl($('#workflowURL').val());
		$('#workflow-spinner').show();
	});
	$(document).on("click",".workflow-showmodal-iframe",function () {
		workflowOffsiteIFrame( 
			$(this).data('posturl'),
			$(this).data('action'),
			$(this).data('miscdata'),
			'#workflowModal' );
	});
	$(document).on("click",".workflow-showmain-iframe",function () {
		workflowOffsiteIFrame( 
			$(this).data('posturl'),
			$(this).data('action'),
			$(this).data('miscdata'),
			'#workflowDisplayMain' );
	});
	$(document).on("click","#workflow-addlink-plus",function () {
		workflowOffsiteIFrame( 
			$('#workflow-addlink-plus').data('posturl'),
			$('#workflow-addlink-plus').data('action'),
			$('#workflow-addlink-plus').data('miscdata'),
			'#workflowModal' );
	});

	window.onpopstate = function(e) {
		if(e.state !== null && e.state.b64mid !== bParam_mid)
			getDataWF(e.state.b64mid, '');
	};

});



$.fn.wfserializeObject = function()
{
   var o = {};
   var a = this.serializeArray();
   $.each(a, function() {
       if (o[this.name]) {
           if (!o[this.name].push) {
               o[this.name] = [o[this.name]];
           }
           o[this.name].push(this.value || '');
       } else {
           o[this.name] = this.value || '';
       }
   });
   return o;
};



function workflowGeneralForm( posturl, wfaction ) {
	var fd = $('#workflowIFrameForm').wfserializeObject();

	var postdata = {
			action: wfaction,
			jsondata: fd
	};
console.log('POST TO: '+posturl);
console.log('POSTDATA = '+JSON.stringify(postdata));
	$.ajax({
		type: 'POST',
		url: posturl,
		data: postdata,
		dataType: 'json',
		success: function (data) {
			if (data.html) {
				$('#workflowiframe-content').html(data.html);
			}},
		error: function (req, status, err) {
			if (req.readyState == 4) {
				$('body').html(req.responseText);
			} else {
				alert('There was an error processing the request.' + status + err + '  ' + JSON.stringify(req));
			}
		}
		});
	}

function workflowSubmitRelated(posturl) {
	var fd = $('#workflowAddRelationForm').wfserializeObject();
	var postdata = {
		action: 'update',
		uuid: $('#workflowuuid').val(),
		mid: $('#workflowmid').val(),
		observer: myzid,
		jsondata: {updates: {
			parameter: 'addrelation',
			relatedlink: $('#workflowrelatelink').val(),
			title: $('#workflowrelatedtitle').val(),
			notes: $('#workflowrelatenote').val()
		}}
	};

	window.parent.postMessage(JSON.stringify({parentwindowid: parentwindowid, message: 'wfitemsubmitted'}),wforiginurl);

	$.ajax({
		type: 'POST',
		url: posturl,
		data: postdata,
		dataType: 'json',
		success: function (data) {
			if (data.html) {
console.log("DATA RETURNED");
				$('#workflow-modaliframe').html(data.html);
				window.parent.postMessage(JSON.stringify({parentwindowid: parentwindowid, message: 'wfitemdatareturned'}),wforiginurl);
			} else {
console.log("No Data Returned");
				$('#workflowModal').modal('hide');
				window.parent.postMessage(JSON.stringify({parentwindowid: parentwindowid, message: 'wfclosemodal'}),wforiginurl);
			}},
		error: function (req, status, err) {
			alert('There was an error processing the request.' + status + err + '  ' + JSON.stringify(req));
			window.parent.postMessage(JSON.stringify({parentwindowid: parentwindowid, message: 'wfitemdatareturned'}),wforiginurl);
			$('#workflowRelateSubmit').show();
		}
	});
}

$(document).ready(function() {
        $(document).on("click","#workflowSubmit",function () {
                $('#workflowSubmit').hide();
		workflowGeneralForm($('#workflowURL').val(),wfaction);
                $('#workflow-spinner').show();
        });
});

window.workflowiframeCloseModal = function() {
	window.parent.postMessage(JSON.stringify({parentwindowid: parentwindowid, message: 'wfclosemodal'}),wforiginurl);
}

window.addEventListener("message",function(event) {
	msginfo = JSON.parse(event.data);
	if (msginfo.parentwindowid != mywindowid) {
		return;
	}
	if (msginfo.message=='wfitemdatareturned') {
		$('#workflow-spinner').hide();
	}
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

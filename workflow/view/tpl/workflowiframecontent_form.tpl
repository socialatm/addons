<html>
<head>
{{$head_css}}
{{$head_js}}
<script>
  wforiginurl="{{$wforiginurl}}";
  wfaction="{{$action}}";
  parentwindowid="{{$parentwindowid}}";
</script>
</head>
<body>
<div class="workflowModalBody" style="padding:15px;">
 <div id='workflowiframe-content'>
	{{if ($title)}}<h5 class="modal-title" id="channelrepModalLabel">{{$title}}</h5>{{/if}}
	<form id='workflowIFrameForm' name="{{$formname}}">
		<input type="hidden" id="workflowSecurityToken" name="form_security_token" value="{{$security_token}}">
		<input type="hidden" id="workflowTrackerXchan" name="workflowTrackerXchan" value="{{$source_xchan}}">
		<input type="hidden" id="workflowURL" name="workflowURL" value="{{$posturl}}">
		<input type="hidden" id="workflowaction" name="action" value="{{$action}}">
		{{$content}}
		<button type="button" id="workflowSubmit" aria-hidden="true" class="btn btn-primary" onClick="{{if ($onclick)}}{{$onclick}}{{else}}return false;{{/if}}">{{$submit}}</button>
	<div id="workflow-spinner" class="spinner-wrapper"><div class="spinner m"></div></div>
      </form>
 </div>
</div>
</body>
</html>

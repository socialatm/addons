<html>
<head>
{{$head_css}}
{{$head_js}}
<script>
  wforiginurl="{{$wforiginurl}}";
  wfaction="newitem";
</script>
</head>
<body>
      <div id='workflowiframe-content'>
      <form id='workflowIFrameForm'>
      <div class="modal-body">
          <input type="hidden" id="workflowSecurityToken" name="form_security_token" value="{{$security_token}}">
	  <input type="hidden" id="workflowTrackerXchan" name="workflowTrackerXchan" value="{{$source_xchan}}">
	  <input type="hidden" id="workflowURL" name="workflowURL" value="{{$posturl}}">
	  <input type="hidden" id="workflowjson" name="datastore" value='{{$jsondata}}'>
	  {{include file="field_input.tpl" field=['workflowSubject',t('Subject'),$subject,t('Brief title for workflow item')]}}
	  {{include file="field_textarea.tpl" field=['workflowBody',t('Details'),$content,t('Summary or initial information')]}}
	  {{$modal_extras}}
          <button type="button" id="workflowSubmit" aria-hidden="true" class="btn btn-primary" onClick="return false;">{{$submit}}</button>
	  <div id="workflow-spinner" class="spinner-wrapper"><div class="spinner m"></div></div>
        </div>
      </form>
      </div>
</body>
</html>

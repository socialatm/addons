<html>
<head>
{{$head_css}}
{{$head_js}}
<script>
  wforiginurl="{{$wforiginurl}}";
</script>
</head>
<body>
      <form id='workflowAddRelationForm'>
      <div class="modal-body">
          <input type="hidden" id="workflowSecurityToken" name="form_security_token" value="{{$security_token}}">
	  <input type="hidden" id="workflowTrackerXchan" name="workflowTrackerXchan" value="{{$source_xchan}}">
	  <input type="hidden" id="workflowURL" name="workflowURL" value="{{$posturl}}">
	  <input type="hidden" id="workflowuuid" name="workflowuuid" value="{{$uuid}}">
	  <input type="hidden" id="workflowmid" name="workflowmid" value="{{$mid}}">
	  {{include file="field_input.tpl" field=['workflowrelatelink',t('Related URL'),"",t('Web address or x-zot: address for linked item')]}}
	  {{include file="field_textarea.tpl" field=['workflowrelatenote',t('Note/info'),"",t('Notes')]}}
          <button type="button" id="workflowRelateSubmit" aria-hidden="true" class="btn btn-primary" onClick="return false;">{{$submit}}</button>
        </div>
      </form>
</body>
</html>

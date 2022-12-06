  <div class="modal-dialog" role="document" style="height:85%;">
    <div class="modal-content" style="height:100%;">
      <div id='workflow-modalcontent' style="height:100%;">
<!--Workflowlist-->
     	<form id='workflowWorkflowList'>
      		<div class="modal-body">
			<input type="hidden" id="workflowURL" value="{{$posturl}}">
			<input type="hidden" id="workflowaction" name="action" value="getmodal_getiframe">
          		<input type="hidden" id="workflowSecurityToken" name="form_security_token" value="{{$security_token}}">
			<input type="hidden" id="workflowjson" name="datastore" value='{{$datastore}}'>
			<label for="group-selection" id="group-selection-lbl">{{$label}}</label>
			<select id="workflowiframeURL" name='iframeurl'>
				{{foreach $workflows as $workflow}}
				<option value='{{$workflow.wfaddr}}'>{{$workflow.name}} ({{$workflow.id}}@{{$workflow.host}}{{if $workflow.primary == 1}} - primary{{/if}})</option>
				{{/foreach}}
			</select>
          		<button type="button" id="workflowSubmitWorkflowUrl" aria-hidden="true" class="btn btn-primary" onClick="return false;">{{$submit}}</button>
	  		<div id="workflow-spinner" class="spinner-wrapper"><div class="spinner m"></div></div>
        		</div>
	</form>
      </div>
    </div>
  </div>

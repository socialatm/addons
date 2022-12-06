<!-- Modal -->
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="channelrepModalLabel">{{$title}}</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div id='workflow-modalcontent'>
      <form id='workflowNewItemForm'>
      <div class="modal-body">
          <input type="hidden" id="workflowSecurityToken" name="form_security_token" value="{{$security_token}}">
	  <input type="hidden" id="workflowTrackerXchan" name="workflowTrackerXchan" value="{{$source_xchan}}">
	  <input type="hidden" id="workflowJSON" name="datastore" value="{{$datastore}}">
	  {{include file="field_input.tpl" field=['workflowSubject',t('Subject'),$subject,t('Brief title for workflow item')]}}
	  {{include file="field_textarea.tpl" field=['workflowBody',t('Details'),$content,t('Summary or initial information')]}}
	  {{$modal_extras}}
          <button type="button" id="workflowSubmit" aria-hidden="true" class="btn btn-primary" onClick="return false;">{{$submit}}</button>
	  <div id="workflow-spinner" class="spinner-wrapper"><div class="spinner m"></div></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
        </div>
      </form>
      </div>
    </div>
  </div>

<!-- Modal -->
  <div class="modal-dialog" role="document" style="height:85%;">
    <div class="modal-content" style="height:100%;">
      <div class="modal-header">
        <h5 class="modal-title" id="channelrepModalLabel">{{$title}}</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div id='workflow-modalcontent' style="height:100%;">
	{{$content}}
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal" onClick="window.workflowCloseModal();">Close</button>
      </div>
    </div>
  </div>

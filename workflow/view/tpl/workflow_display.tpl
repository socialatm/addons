<div class="content-fluid">
<div class="workflow toolbar">
        <div class="workflow toolbar row">
                {{$toolbar}}
        </div>
</div>
		<script>
			var wfitemid = {{$items.0.item_id}};
			var itemPostURL = '{{$posturl}}';
			var uuid = '{{$uuid}}';
			var mid = '{{$mid}}';
		</script>
<div class="row">
	<div class="col-xs-12 col-sm-8 col-md-9">
		<div id="wfitemdata">
		{{include file="./workflow_display_wfitemdata.tpl"}}
		</div>
		<div class="row" style="min-height:500px;padding:10px;">
			<div id="workflowDisplayMain" class="col-12 workflow wfmainiframe" style="height:max-height;border:solid 1px;padding:0px;">{{$maindata}}</div>
		</div>
	</div>
	<div class="col-xs-12 col-sm-4 col-md-3">
	{{$sidebar}}
	</div>
	</div>
</div>
</div>

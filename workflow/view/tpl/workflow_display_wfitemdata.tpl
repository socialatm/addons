			<div class="row">
			    <div class="col-12 workflow wfheading">
				{{$items.0.title}}
 				<a href="#" onclick='return false;' class="workflow-showmodal-iframe" data-posturl='{{$posturl}}' data-action='{{$addlinkaction}}' data-miscdata='{{$edittaskjsondata}}' data-toggle="tooltip" title="edit"><i class="fa fa-pencil"></i></a>
			    </div>
			</div>
			<div class="row">
				{{foreach $itemmeta as $meta}}
				<div class="workflow wfmeta-item {{if $meta.cols}}{{$meta.cols}}{{/if}}">{{$meta.html}}
				</div>
				{{/foreach}}
			</div>
			<div class="row">
			    <div class="col-12 workflow wfcontent">
				{{$body.html}}
			    </div>
			</div>

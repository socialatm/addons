			{{if ($items.0.related)}}
				<h4>Related Links</h4>
				{{foreach $items.0.related as $related}}
					<div class="workflow wfrelatedlink">
						<b>{{if $related.title}}{{$related.title}}{{else}}{{$related.relatedlink|wordwrap:18:" ":true}}{{/if}}</b><br>
						<!--
						<a href="#" class='workflow-showmodal-iframe' onclick="return false;" data-posturl='{{$posturl}}' data-action='{{$related.action}}' data-miscdata='{{$related.jsondata}}' data-toggle="tooltip" title="pop-up"><i class='fa fa-window-restore'></i></a>
						-->
						<a href="#" class='workflow-showmain-iframe' onclick="return false;" data-posturl='{{$posturl}}' data-action='{{$related.action}}' data-miscdata='{{$related.jsondata}}' data-toggle="tooltip" title="pop-up"><i class='fa fa-window-restore'></i></a>
 						<a href="{{$related.relurl}}" target="{{$related.uniq}}" data-toggle="tooltip" title="new window"><i class="fa fa-external-link"></i></a>
 						<a href="#" onclick='return false;' class="workflow-showmodal-iframe" data-posturl='{{$posturl}}' data-action='{{$addlinkaction}}' data-miscdata='{{$related.jsoneditdata}}' data-toggle="tooltip" title="edit"><i class="fa fa-pencil"></i></a>
						<br>
						{{if $related.notes}}{{$related.notes}}{{else}}
						<span style="font-size:.75em">{{$related.relatedlink|truncate:50:"...":true:true|wordwrap:25:" ":true}}</span>
						{{/if}}
					<hr>
					</div>
				{{/foreach}}
			{{/if}}
		<div class="workflow-ui"><a href="#" id='workflow-addlink-plus' onclick="return false;" data-posturl='{{$posturl}}' data-action='{{$addlinkaction}}' data-miscdata='{{$addlinkmiscdata}}' data-toggle="tooltip" title="Add new link"><i class="fa fa-plus"></i></a></div>

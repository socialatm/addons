<div class="workflow toolbar">
	<div class="workflow toolbar row">
		{{$toolbar}}
	</div>
</div>
<div class="workflow-header">
	<div class="title col-12" style='font-size:3em;font-weight:heavy;text-align:center;'>{{$title}}</div>
	<div class="headerxtras col-12">{{$headerextras}}</div>
</div>
<div class="workflow-item-list">
{{foreach $items as $item}}
<div class="row">
   <div class="workflow-item-{{cycle values="odd,even"}} col-12">
	<div class="row" style="background-color:rgba(0,0,0,0.2);">
		<div class="col-xs-12 col-sm-9" style='font-size:2em;font-weight:heavy;'><a href="{{$item.url}}" target=_{{$item.target}}>{{$item.title}}</a></div>
		<div class="col-xs-12 col-sm-3" style="font-size:1em;font-weight:normal;text-align:right;">{{$item.channelname}}</div>
	</div>
	<div class="row" style="background-color:rgba(0,0,0,0.3); min-height:4em;">
		<div class="col-xs-12 col-sm-7 min-height:300px;">{{$item.body}}</div>
		<div class='col-xs-12 col-sm-5 wfmeta-container' style='background-color:rgba(0,0,0,0.1);text-align:right;'>{{$item.listextras}}</div>
	</div>
	<div class="row" style="background-color:rgba(0,0,0,0.4);">
	</div>
	<div class="row" style="background-color:#fff; width:100%;font-size:.2em;">
		<div class='col-12'>&nbsp;</div>
	</div>
   </div>
</div>
{{/foreach}}
</div>

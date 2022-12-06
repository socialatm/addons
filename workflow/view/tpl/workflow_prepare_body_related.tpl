<div style="width:100%;padding:1em 2em 1em 2em;background-color:rgba(40,40,230,.2);font-size:1em;">
<div><h4>Related Workflow Items / Issues:</h4></div>
{{foreach $relateditems as $item}}
<div style='padding-left:25px;text-indent:-25px;'><a href="{{$item.plink}}" style='font-weight:bold;'>{{$item.title}}</a> <span style='font-size:.8em;'>(Workflow: {{$item.owner.xchan_name}}  priority: {{$item.workflowdata.priority}})</span></div>
{{/foreach}}
</div>

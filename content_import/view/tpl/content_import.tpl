<h3>{{$header}}</h3>

<p class="descriptive-text">{{$desc}}</p>

<form action="content_import" method="post" >

{{include file="field_input.tpl" field=$fr_server}}
{{include file="field_checkbox.tpl" field=$items}}
{{include file="field_checkbox.tpl" field=$files}}
{{include file="field_input.tpl" field=$since}}
{{include file="field_input.tpl" field=$until}}

<input type="submit" name="submit" value="{{$submit}}" />
</form>


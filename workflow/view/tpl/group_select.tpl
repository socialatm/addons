<div class="form-group field custom">
<label for="group-selection" id="group-selection-lbl">{{$label}}</label>
<select class="form-control" name="{{if $name}}{{$name}}{{else}}group-selection{{/if}}" id="{{if $name}}{{$name}}{{else}}group-selection{{/if}}" >
{{foreach $groups as $group}}
<option value="{{$group.id}}" {{if $group.selected}}selected="selected"{{/if}} >{{$group.name}}</option>
{{/foreach}}
</select>
</div>

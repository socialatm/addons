
	<div class="panel">
		<div class="section-subtitle-wrapper" role="tab" id="settings-group-{{$groupid}}">
			<h3>
				<a data-toggle="collapse" data-target="#wfsettings-{{$groupid}}" href="#">
					{{$title}}
				</a>
			</h3>
		</div>
		<div id="wfsettings-{{$groupid}}" class="collapse" role="tabpanel" aria-labelledby="settings-group-{{$groupid}}" data-bs-parent="#settings">
			<form action='settings/workflow' method='post' id='settings-form-{{$groupid}}'>
				<input type='hidden' name='form_security_token' value='{{$form_security_token}}' />
				<input type='hidden' name='formname' value="{{$formname}}">
				{{$content}}
				<div class="settings-submit-wrapper" >
                                        <button type="submit" name="submit" class="btn btn-primary">{{$submit}}</button>
                                </div>
			</form>
		</div>
	</div>

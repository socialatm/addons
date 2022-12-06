<div style="display:none;">
	<dl class="entity_uid">
 		<dt>Uid</dt>
 		<dd>
			<span class="uid p-uid">{{$guid}}</span>
 		</dd>
	</dl>
	<dl class='entity_nickname'>
		<dt>Nickname</dt>
		<dd>		
			<span class="nickname p-nickname">{{$nickname}}</span>
		</dd>
	</dl>
	<dl class='entity_full_name'>
		<dt>Full name</dt>
		<dd>
			<span class='fn p-name'>{{$fullname}}</span>
		</dd>
	</dl>

	<dl class='entity_first_name'>
		<dt>First name</dt>
		<dd>
		<span class='given_name p-given-name'>{{$firstname}}</span>
		</dd>
	</dl>
	<dl class='entity_family_name'>
		<dt>Family name</dt>
		<dd>
		<span class='family_name p-family-name'>{{$lastname}}</span>
		</dd>
	</dl>
	<dl class="entity_url">
		<dt>URL</dt>
		<dd>
			<a href="{{$podloc}}/" id="pod_location" class="url" rel="me" >{{$podloc}}/</a>
		</dd>
	</dl>
	<dl class="entity_photo">
		<dt>Photo</dt>
		<dd>
			<img class="photo u-photo avatar" height="300" width="300" src="{{$photo300}}" />
		</dd>
	</dl>
	<dl class="entity_photo_medium">
		<dt>Photo</dt>
		<dd> 
			<img class="photo u-photo avatar" height="100" width="100" src="{{$photo100}}" />
		</dd>
	</dl>
	<dl class="entity_photo_small">
		<dt>Photo</dt>
		<dd>
			<img class="photo u-photo avatar" height="50" width="50" src="{{$photo50}}" />
		</dd>
	</dl>
	<dl class="entity_searchable">
		<dt>Searchable</dt>
		<dd>
			<span class="searchable">{{$searchable}}</span>
		</dd>
	</dl>
	<dl class="entity_key">
		<dt>Key</dt>
		<dd>
		<pre class="key">{{$pubkey}}</pre>
		</dd>
	</dl>
</div>

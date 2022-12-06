<form id="attach_edit_form_{{$file.id}}" action="flashcards/{{$channelnick}}/permissions/{{$file.id}}" method="post" class="acl-form" data-form_id="attach_edit_form_{{$file.id}}" data-allow_cid='{{$allow_cid}}' data-allow_gid='{{$allow_gid}}' data-deny_cid='{{$deny_cid}}' data-deny_gid='{{$deny_gid}}'>
    <input type="hidden" name="channelnick" value="{{$channelnick}}" />
    <input type="hidden" name="filehash" value="{{$file.hash}}" />
    <input type="hidden" name="uid" value="{{$uid}}" />
    <input type="hidden" name="fileid" value="{{$file.id}}" />
    <input type="hidden" name="boxid" value="{{$boxid}}" />
    <div>
        <span class="navbar-brand">
            <span>{{$permissions}}</span>
        </span>
        <div id="attach-edit-perms" class="btn-group pull-right">
            <button id="dbtn-acl" class="btn btn-outline-secondary btn-sm" data-toggle="modal" data-target="#aclModal" title="{{$permset}}" type="button">
                <i id="jot-perms-icon" class="fa fa-{{$lockstate}} jot-icons"></i>
            </button>
            <button id="dbtn-submit" class="btn btn-primary btn-sm" type="submit" name="submit">
                {{$submit}}
            </button>
        </div> 
    </div>
</form>


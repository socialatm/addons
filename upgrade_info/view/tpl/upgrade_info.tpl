<div id="upgrade_info_aside" class="alert alert-info alert-dismissible fade show">
	<h3><i class="fa fa-hubzilla"></i> {{$title}}</h3>
	<hr>
	<p>{{$content.0}}</p>
	<p class="text-center"><strong>{{$content.1}}</strong></p>
	<p>{{$content.2}} {{$content.3}} {{$content.4}}</p>
	<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
	<button id="upgrade_info_dismiss" type="button" class="btn btn-sm btn-success"><i class="fa fa-check"></i> {{$dismiss}}</button>
	<script>
		$('#upgrade_info_dismiss').click(function() {
			$.post(
				'pconfig',
				{
					'aj' : 1,
					'cat' : 'upgrade_info',
					'k' : 'version',
					'v' : '{{$std_version}}',
					'form_security_token' : '{{$form_security_token}}'
				}
			)
			.done(function() {
				$('#upgrade_info_aside').fadeOut('fast');
			});
		});
	</script>
</div>

$(function() {

	$('.AccountLoginForm form').ajaxFormController({
		success: function (result, statusText, xhr, $form) {
			if (result.success) {
				var backUrl = $.urlParam('backUrl');
				if (backUrl != null) {
				location.href = backUrl;
			}
			else {
				location.reload();
			}
		} else {
			
			$form.find('.errorMessage').remove();
			for (var field in result.errors) {
				
				$form.find('input[name="'+field+'"]').after('<div class="errorMessage">'+_(result.errors[field])+'</div>');;
			
			}
				
		}
	}});
	$('.AccountLoginForm form').find('button[disabled]').removeAttr('disabled');
	$('.logoutButton').click(function() {
		$.queryController('Account/Logout',function (result, statusText, xhr, $form) {
			if (result.success) {
				location.reload();
			}
		});
	});
	
	
	$('form.AccountSignupForm').ajaxFormController({
		success: function (result, statusText, xhr, $form) {
			$form.find('.errorMessage').remove();
			$('form.AccountSignupForm .CAPTCHA').html('');
			if (result.success) {
				var backUrl = $.urlParam('backUrl');
				if (backUrl != null) {
				location.href = backUrl;
			}
			else {
				location.href = '/';
			}
		} else {
			
			for (var field in result.errors) {
				
				if (field == 'captcha') {
					$('form.AccountSignupForm .CAPTCHA').captcha().after('<div class="errorMessage">'+_(result.errors[field])+'</div>');
				} else {
					$form.find('input[name="'+field+'"]').after('<div class="errorMessage">'+_(result.errors[field])+'</div>');
				}
			
			}
		}
	}}).find('button[disabled]').removeAttr('disabled');
	
	
	$("form.AccountSignupForm input[name='username']").change(function() {
		if ($(this).val().length > 3) {
			$.queryController('Account/UsernameAvailablityCheck', function(data) {
				if (data.success) {
					var div = $('.usernameAvailability');
					div.html(data.available?'Username available.':'Username already taken.').i18n();
					if (data.available) {
						div.removeClass('denyMsg');
						div.addClass('allowMsg');
					}
					else {
						div.removeClass('allowMsg');
						div.addClass('denyMsg');
					}
				}
			}, {username: $(this).val()}
			);
		}
	});

});

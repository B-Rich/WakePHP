;(function($) {$.extend($.fn, {smartTable: function(overload) {
var settings = {
	"oLanguage": {
		"sProcessing":   _("Processing..."),
		"sLengthMenu":   _("Show _MENU_ entries"),
		"sZeroRecords":  _("No matching records found"),
		"sInfo":         _("Showing _START_ to _END_ of _TOTAL_ entries"),
		"sInfoEmpty":    _("Showing 0 to 0 of 0 entries"),
		"sInfoFiltered": _("(filtered from _MAX_ total entries)"),
		"sInfoPostFix":  "",
		"sSearch":       _("Search:"),
		"sUrl":          _(""),
		"oPaginate": {
			"sFirst":    _("First"),
			"sPrevious": _("Previous"),
			"sNext":     _("Next"),
			"sLast":     _("Last")
		}
	},
	"aoColumns": function(table) {
		var columns = [];
		$('thead > tr > th', $(table)).each(function(i, el) {
			columns.push($(el).hasClass('disabledSorting') ? {bSortable: false} : null);
		});
		return columns;
	}(this),
	"bProcessing": true,
	"bServerSide": true,
	"sAjaxSource": $(this).data('source'),
	"fnServerData": function ( sSource, aoData, fnCallback ) {
		setTimeout(function() {$table.sSource = sSource;}, 0);
		$.queryController(sSource, fnCallback, aoData);
	},
	"fnDrawCallback": function() {
		setTimeout(function () {
			$($table.fnGetNodes()).each(function(i, el) {
				var id = $('td:last', el).text();
				$(el).attr('id', id);
				$('td:last', el).html('<a href="#">' + _('Delete') + '</a>').find('a').data('accountId', id).click(function() {
					if (confirm(_("Are you sure?"))) {
						$.queryController('Account/DeleteAccount', function(data) {
							if (!data.success) {alert(_(data.error));}
							else {
								$table.fnDeleteRow();
							}									
						}, {accountId: $(this).data('accountId')});
					}
					return false;
				});
			});
			$('td:not(:last)', $table.fnGetNodes()).editable( '/component/' + $table.sSource + '/json', {
				"callback": function( sValue, y ) {
					var aPos = $table.fnGetPosition( this );
					$table.fnUpdate( sValue, aPos[0], aPos[1] );
				},
				"submitdata": function ( value, settings ) {
					return {
						"action": "EditAccountColumn",
						"accountId": this.parentNode.getAttribute('id'),
						"column": $table.fnGetPosition( this )[2]
					};
				},
				'placeholder': $('<div>').html('<i class="i18n">Not specified</i>').i18n().html(),
				"height": "25px",
				"cssclass" : "editableInputForm",
			});		
		}, 0);
	}
};
$.extend(settings, overload);
$table = $(this);
$table.dataTable(settings);
return this;
}});
})(jQuery);

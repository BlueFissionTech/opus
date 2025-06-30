import "../datatables";
import { Model, Reactor, ReactiveTemplate } from "../scripts/reactive_template.js";
import Template from "../scripts/template.js";
import PresentationModal from "../scripts/presentation-modal.js"

//== Class definition
var ModuleAddOns = function() {
	var dataTable;
	const inputOptions = {
	  rejectOn: isNaN,
	  mutator: Number
	};

	const model = new Model;
	model.addon_id = new Reactor(0);
	model.name = new Reactor("");
	model.version = new Reactor("");
	model.namespace = new Reactor("");
	model.path = new Reactor("");
	model.description = new Reactor("");
	model.is_active = new Reactor("");

	model.status = app.computed(() => model.is_active.value ? 'Active' : 'Inactive' );

	const settings = new Model;
	settings.settings_id = new Reactor(0);
	settings.addon_id = new Reactor(0);
	settings.value = new Reactor("");

	app.assign('addon_name', model.name);
	app.set('.addon-id-field', model.addon_id, 'value', inputOptions);
	app.set('.addon-name-field', model.name, 'value');
	app.set('.addon-description-field', model.description, 'value');
	app.set('.addon-is-active-field', model.is_active, 'value');

	// app.set('.settings-id', settings.settings_id, 'value', inputOptions);
	// app.set('.settings-addon-id', settings.addon_id, 'value', inputOptions);
	// app.set('.settings-value', settings.name, 'value');

	var ready = function() {
		$('#addon-edit-screen').hide();
		// feather.replace();
	};
	
	var showEditScreen = function() {
		$('#addon-listing-screen').fadeOut(200, function(e) {
			$('#addon-edit-screen').fadeIn(200);
		});
	};

	var showListingScreen = function() {
		$('#addon-edit-screen').fadeOut(200, function(e) {
			$('#addon-listing-screen').fadeIn(200);
		});
	};

	var screenHome = function() {
		$('.home-btn').click(function(e) {
			showListingScreen();
		});
	};

	var loadAddOnList = function() {
		dataTable = $('#dataTable').DataTable({
			ajax: {
				url: '/api/admin/addons',
				dataSrc: 'list'
			},
			aoColumnDefs: [
        { "bSortable": false, "aTargets": [ 3 ] }, 
        { "bSearchable": false, "aTargets": [ 2, 3 ] }
	    ],
			columns: [
				{
        	data: 'name',
					render: function(data, type, row) {
					  return `<a href="#" class="show-btn">${data}</a>`;
					},
				},
        { data: 'description' },
        { 
        	data: 'is_active',
        	render: function ( data, type, row ) {
				    return data == 1 ? `<span class="badge rounded-pill bg-success">Active</span>` : `<span class="badge rounded-pill bg-secondary">Inactive</span>`;
			  	}
        },
        {
				  data: null,
				  render: function ( data, type, row ) {
				  	if (row.addon_id === null) {
					    return '&nbsp;<button class="btn btn-sm btn-success install-btn"><i class="fa fa-box-open"></i></button> '
					    +'&nbsp;<button class="btn btn-sm btn-danger remove-btn"><i class="fa fa-trash"></i></button> ';
					  } else {
					  	let btnClass = row.is_active == 1 ? 'primary' : 'secondary';
					  	
					  	return '&nbsp;<button class="btn btn-sm btn-'+btnClass+' activate-btn"><i class="fa fa-check"></i></button> ' 
					    // +'&nbsp;<button class="btn btn-sm btn-secondary settings-btn"><i class="fa fa-gear"></i></button> '
					    +'&nbsp;<button class="btn btn-sm btn-warning uninstall-btn"><i class="fa fa-box"></i></button> ';
					  }
			  	}
				}
	   	]
		});
	};

	var addonShow = function() {
		$("#dataTable").on("click", ".show-btn", function(e) {
			e.preventDefault();
		// Get the row data (tr element) containing the clicked cell
		var data = dataTable.row( $(this).parents('tr') ).data();
		
			app.api.addon.read(data.addon_id, function(response) {
				model.update(response.data);
				// showEditScreen();
				const template = new Template('#addon-detail-display-item', model);
				template.render();
				template.swap('#addon-details');
	    });
		});
	};

	var addonNew = function() {
		$('#addon-add-btn').on('click', function(e) {
			e.preventDefault();
			model.clear();
			// $('#modalNewAddOn').modal('show');
			showEditScreen();
    });
	};

	var addonDelete = function() {
		$('#addon-delete-btn').click(function() {
			app.api.addon.delete(model, function(response) {
				app.ui.notice("AddOn has been deleted");
        dataTable.ajax.reload();
			});
		});
	};
	
	var addonEdit = function() {
		$('#dataTable').on('click', '.edit-btn', function(e) {
			e.preventDefault();
			let data = dataTable.row( $(this).parents('tr') ).data();
			let addon_id = data.addon_id;
			app.api.addon.read(addon_id, function(response) {
				model.update(response.data);
				$('#modalNewAddOn').modal('show');
			});
    });
	};
	
	var addonInstall = function() {
		$('#dataTable').on('click', '.install-btn', function(e) {
			e.preventDefault();
			let data = dataTable.row( $(this).parents('tr') ).data();

			app.api.addon.install(data, function(response) {
				app.ui.notice("Addon '"+data.name+"' installed");
        dataTable.ajax.reload();
			});
    });
	};
	
	var addonUninstall = function() {
		$('#dataTable').on('click', '.uninstall-btn', function(e) {
			e.preventDefault();
			let data = dataTable.row( $(this).parents('tr') ).data();

			app.api.addon.uninstall(data, function(response) {
				app.ui.notice("Addon '"+data.name+"' uninstalled");
        dataTable.ajax.reload();
			});
    });
	};
	
	var addonToggleActivation = function() {
		$('#dataTable').on('click', '.activate-btn', function(e) {
			e.preventDefault();
			let data = dataTable.row( $(this).parents('tr') ).data();

			app.api.addon.activate(data, function(response) {
				app.ui.notice("Addon '"+data.name+"' status changed");
        dataTable.ajax.reload();
			});
    });
	};

	var addonSave = function() {
		$('.save-btn').click(function(e) {
			e.preventDefault();
			app.api.addon.save(model, function(response) {
				if (!response.id) {
					app.ui.notice(response.status, 'error');
					return;
				}
				model.clear();
        dataTable.ajax.reload();
        $('#modalNewAddOn').modal('hide');
				app.ui.notice("AddOn has been saved");
			});
		});

		$('#addon-save-btn').click(function(e) {
			e.preventDefault();
			app.api.addon.save(model, function(response) {
				if (!response.id) {
					app.ui.notice(response.status, 'error');
					return;
				}
				model.clear();
        dataTable.ajax.reload();
        showListingScreen();
				app.ui.notice("AddOn has been saved");
			});
		});
	};

	var addonManage = function() {
		$('#dataTable').on('click', '.settings-btn', function(e) {
			e.preventDefault();
			let data = dataTable.row( $(this).parents('tr') ).data();
			let addon_id = data.addon_id;
			app.api.addon_settings.read(addon_id, function(response) {
				$('#modalAddOnSettings').modal('show');
			});
    });
	};

	var addonConfigure = function() {
		$('#addon-settings-save-btn').click(function() {
			app.api.addon_settings.save(settings, function(response) {
        $('#modalNewAddOn').modal('hide');
				app.ui.notice("AddOn settings have been saved");
			});
		});
	};

	return {
        //main function to initiate the module
        init: function () {

        	screenHome();
        	loadAddOnList();
        	addonShow();
        	addonNew();
        	// addonEdit();
        	addonToggleActivation();
        	addonInstall();
        	addonUninstall();
        	addonManage();
        	addonConfigure();
        	addonSave();

        	ready();
        }
    };
}();

jQuery(document).ready(function() {
    ModuleAddOns.init();
});

export default ModuleAddOns;
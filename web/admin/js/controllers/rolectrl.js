var RoleCtrl = function ($scope, RolesRelated, User, App, Service, $http) {
    $scope.$on('$routeChangeSuccess', function () {
        $(window).resize();
    });
    Scope = $scope;
    Scope.role = {users: [], apps: [], role_service_accesses: [], role_system_accesses:[], lookup_keys: []};
    Scope.keyData = [];
    Scope.action = "Create new ";
    Scope.actioned = "Created";
    $('#update_button').hide();
    //$("#alert-container").empty().hide();
    //$("#success-container").empty().hide();

	// keys
	var keyInputTemplate = '<input class="ngCellText colt{{$index}}" ng-model="row.entity[col.field]" ng-change="enableKeySave()" />';
	var keyCheckTemplate = '<div style="text-align:center;"><input style="vertical-align: middle;" type="checkbox" ng-model="row.entity[col.field]" ng-change="enableKeySave()"/></div>';
	var keyButtonTemplate = '<div><button id="key_save_{{row.rowIndex}}" class="btn btn-small btn-inverse" disabled=true ng-click="saveKeyRow()"><li class="icon-save"></li></button><button class="btn btn-small btn-danger" ng-click="deleteKeyRow()"><li class="icon-remove"></li></button></div>';
	Scope.keyColumnDefs = [
		{field:'name', width:100},
		{field:'value', enableFocusedCellEdit:true, width:200, enableCellSelection:true, editableCellTemplate:keyInputTemplate },
		{field:'private', cellTemplate:keyCheckTemplate, width:75},
		{field:'Update', cellTemplate:keyButtonTemplate, width:80}
	];
	Scope.keyOptions = {data:'keyData', width:500, columnDefs:'keyColumnDefs', canSelectRows:false, displaySelectionCheckbox:false};
	Scope.updateKeys = function () {
		$("#key-error-container").hide();
		if (!Scope.key) {
			return false;
		}
		if (!Scope.key.name || !Scope.key.value) {
			$("#key-error-container").html("Both name and value are required").show();
			return false;
		}
		if (checkForDuplicate(Scope.keyData, 'name', Scope.key.name)) {
			$("#key-error-container").html("Key already exists").show();
			$('#key-name, #key-value').val('');
			return false;
		}
		var newRecord = {};
		newRecord.name = Scope.key.name;
		newRecord.value = Scope.key.value;
		newRecord.private = !!Scope.key.private;
		Scope.keyData.push(newRecord);
		Scope.key = null;
		$('#key-name, #key-value').val('');
	}
	Scope.deleteKeyRow = function () {
		var name = this.row.entity.name;
		Scope.keyData = removeByAttr(Scope.keyData, 'name', name);

	}
	Scope.saveKeyRow = function () {
		var index = this.row.rowIndex;
		var newRecord = this.row.entity;
		var name = this.row.entity.name;
		updateByAttr(Scope.keyData, "name", name, newRecord);
		$("#key_save_" + index).prop('disabled', true);
	};
	Scope.enableKeySave = function () {
		$("#key_save_" + this.row.rowIndex).prop('disabled', false);
	};

    Scope.AllUsers = User.get();
    Scope.Apps = App.get();
    // service access
    Scope.ServiceComponents = {};
    Scope.Services = Service.get(function (data) {
        var services = data.record;
        services.unshift({id: 0, name: "All", type: ""});
        services.forEach(function (service, index) {
            Scope.ServiceComponents[index] = [];
            var allRecord = {name: '*', label: 'All', plural: 'All'};
            Scope.ServiceComponents[index].push(allRecord);
            if(service.id > 0) {
                $http.get('/rest/' + service.api_name + '?app_name=admin&fields=*').success(function (data) {
                    // some services return no resource array
                    if (data.resource != undefined) {
                        Scope.ServiceComponents[index] = Scope.ServiceComponents[index].concat(data.resource);
                    }
                }).error(function(){});
            }
        });
    });
    Scope.uniqueServiceAccess = function () {
        var size = Scope.role.role_service_accesses.length;
        for (i = 0; i < size; i++) {
            var access = Scope.role.role_service_accesses[i];
            var matches = Scope.role.role_service_accesses.filter(function(itm){return itm.service_id === access.service_id && itm.component === access.component;});
            if (matches.length > 1) {
                return false;
            }
        }
        return true;
    }
    // system access
    Scope.SystemComponents = [];
    var allRecord = {name: '*', label: 'All', plural: 'All'};
    Scope.SystemComponents.push(allRecord);
    $http.get('/rest/system?app_name=admin&fields=*').success(function (data) {
        Scope.SystemComponents = Scope.SystemComponents.concat(data.resource);
    }).error(function(){});
    Scope.uniqueSystemAccess = function () {
        var size = Scope.role.role_system_accesses.length;
        for (i = 0; i < size; i++) {
            var access = Scope.role.role_system_accesses[i];
            var matches = Scope.role.role_system_accesses.filter(function(itm){return itm.component === access.component;});
            if (matches.length > 1) {
                return false;
            }
        }
        return true;
    }
    Scope.cleanServiceAccess = function () {
        var size = Scope.role.role_service_accesses.length;
        for (i = 0; i < size; i++) {
            delete Scope.role.role_service_accesses[i].show_filters;
        }
    }
    Scope.FilterOps = ["=", "!=",">","<",">=","<=", "in", "not in", "starts with", "ends with", "contains"];

    Scope.Roles = RolesRelated.get();

    Scope.save = function () {

        if (!Scope.uniqueServiceAccess()) {
            $.pnotify({
                title: 'Roles',
                type: 'error',
                text: 'Duplicate service access entries are not allowed.'
            });
            return;
        }
        if (!Scope.uniqueSystemAccess()) {
            $.pnotify({
                title: 'Roles',
                type: 'error',
                text: 'Duplicate system access entries are not allowed.'
            });
            return;
        }
        Scope.cleanServiceAccess();

        var id = this.role.id;
        Scope.role.lookup_keys = Scope.keyData;
        RolesRelated.update({id: id}, Scope.role, function () {
            updateByAttr(Scope.Roles.record, 'id', id, Scope.role);
            Scope.promptForNew();
            //window.top.Actions.showStatus("Updated Successfully");

            // Success Message
            $.pnotify({
                title: 'Roles',
                type: 'success',
                text: 'Role Updated Successfully'
            });
        }, function (response) {
            //$("#alert-container").html(response.data.error[0].message).show();

            var code = response.status;
            if (code == 401) {
                window.top.Actions.doSignInDialog("stay");
                return;
            }
            $.pnotify({
                title: 'Error',
                type: 'error',
                hide: false,
                addclass: "stack-bottomright",
                text: getErrorString(response)
            });
        });
    };
    Scope.create = function () {

        if (!Scope.uniqueServiceAccess()) {
            $.pnotify({
                title: 'Roles',
                type: 'error',
                text: 'Duplicate service access entries are not allowed.'
            });
            return;
        }
        if (!Scope.uniqueSystemAccess()) {
            $.pnotify({
                title: 'Roles',
                type: 'error',
                text: 'Duplicate system access entries are not allowed.'
            });
            return;
        }
        Scope.cleanServiceAccess();

		Scope.role.lookup_keys = Scope.keyData;
		RolesRelated.save(Scope.role, function (data) {
            Scope.Roles.record.push(data);
            //window.top.Actions.showStatus("Created Successfully");
            Scope.promptForNew();

            // Success Message
            $.pnotify({
                title: 'Roles',
                type: 'success',
                text: 'Role Created Successfully'
            });

        }, function (response) {
            //$("#alert-container").html(response.data.error[0].message).show();

            var code = response.status;
            if (code == 401) {
                window.top.Actions.doSignInDialog("stay");
                return;
            }
            $.pnotify({
                title: 'Error',
                type: 'error',
                hide: false,
                addclass: "stack-bottomright",
                text: getErrorString(response)
            });
        });
    };

    Scope.isUserInRole = function () {
        var currentUser = this.user;
        var inRole = false;
        if (Scope.role.users) {
            angular.forEach(Scope.role.users, function (user) {
                if (angular.equals(user.id, currentUser.id)) {
                    inRole = true;
                }
            });
        }
        return inRole;
    };

    Scope.isAppInRole = function () {

        var currentApp = this.app;
        var inRole = false;
        if (Scope.role.apps) {
            angular.forEach(Scope.role.apps, function (app) {
                if (angular.equals(app.id, currentApp.id)) {
                    inRole = true;
                }
            });
        }
        return inRole;
    };
    Scope.addAppToRole = function () {
        if (checkForDuplicate(Scope.role.apps, 'id', this.app.id)) {
            Scope.role.apps = removeByAttr(Scope.role.apps, 'id', this.app.id);
        } else {
            Scope.role.apps.push(this.app);
        }
    };
    $scope.updateUserToRole = function () {
        if (checkForDuplicate(Scope.role.users, 'id', this.user.id)) {
            Scope.role.users = removeByAttr(Scope.role.users, 'id', this.user.id);
        } else {
            Scope.role.users.push(this.user);
        }
    };

    // service access

    Scope.removeServiceAccess = function () {

        var rows = Scope.role.role_service_accesses;
        rows.splice(this.$index, 1);
    };

    Scope.newServiceAccess = function () {

        var newAccess = {"access": "Full Access", "component": "*", "service_id": 0};
        newAccess.filters = [];
        newAccess.filter_op = "AND";
        newAccess.show_filters = false;
        Scope.role.role_service_accesses.push(newAccess);
    }

    Scope.newServiceAccessFilter = function () {

        var newFilter = {"name": "", "operator": "=", "value": ""};
        this.service_access.filters.push(newFilter);
    }

    Scope.removeServiceAccessFilter = function () {

        console.log(this);
        var rows = this.service_access.filters;
        rows.splice(this.$index, 1);
    };

    Scope.selectService = function () {

        this.service_access.component = "*";
        if (!Scope.allowFilters(this.service_access.service_id)) {
            this.service_access.filter_op = "AND";
            this.service_access.filters = [];
        }
    }

    Scope.serviceId2Index = function (id) {

        var size = Scope.Services.record.length;
        for (i = 0; i < size; i++) {
            if (Scope.Services.record[i].id === id) {
                return i;
            }
        }
        return -1;
    };

    Scope.allowFilters = function (id) {

        var size = Scope.Services.record.length;
        for (i = 0; i < size; i++) {
            if (Scope.Services.record[i].id === id) {
                switch (Scope.Services.record[i].type) {
                    case "Local SQL DB":
                    case "Remote SQL DB":
                    case "NoSQL DB":
                    case "Salesforce":
                        return true;
                    default:
                        return false;
                }
            }
        }
        return false;
    };

    Scope.toggleServiceAccessFilter = function () {

        this.service_access.show_filters = !this.service_access.show_filters;
    };

    Scope.toggleServiceAccessOp = function () {

        if (this.service_access.filter_op === "AND") {
            this.service_access.filter_op = "OR";
        } else {
            this.service_access.filter_op = "AND";
        }
    };

    // system access

    Scope.removeSystemAccess = function () {

        var rows = Scope.role.role_system_accesses;
        rows.splice(this.$index, 1);
    };

    Scope.newSystemAccess = function () {

        var newAccess = {"access": "Read Only", "component": "user"};
        Scope.role.role_system_accesses.push(newAccess);
    }

    //ADDED PNOTIFY
    $scope.delete = function () {
        var which = this.role.name;
        if (!which || which == '') {
            which = "the role?";
        } else {
            which = "the role '" + which + "'?";
        }
        if (!confirm("Are you sure you want to delete " + which)) {
            return;
        }
        var id = this.role.id;
        RolesRelated.delete({ id: id }, function () {
            Scope.promptForNew();

            //window.top.Actions.showStatus("Deleted Successfully");
            $("#row_" + id).fadeOut();

            // Success message
            $.pnotify({
                title: 'Roles',
                type: 'success',
                text: 'Role deleted.'
            });
        }, function(response) {

            var code = response.status;
            if (code == 401) {
                window.top.Actions.doSignInDialog("stay");
                return;
            }
            $.pnotify({
                title: 'Error',
                type: 'error',
                hide: false,
                addclass: "stack-bottomright",
                text: getErrorString(response)
            });
        });

        // Shouldn't prompt for new on failure
        //Scope.promptForNew();
    };
    $scope.promptForNew = function () {
        angular.element(":checkbox").attr('checked', false);
        Scope.action = "Create new";
        Scope.actioned = "Created";
        Scope.role = {users: [], apps: [], role_service_accesses: [], role_system_accesses:[], lookup_keys: []};
        Scope.keyData = [];
        $('#save_button').show();
        $('#update_button').hide();
        //$("#alert-container").empty().hide();
        $("tr.info").removeClass('info');
        $(window).scrollTop(0);
    };
    $scope.showDetails = function () {
        //angular.element(":checkbox").attr('checked',false);
        Scope.action = "Edit this ";
        Scope.actioned = "Updated";
        Scope.role = angular.copy(this.role);
        Scope.users = angular.copy(Scope.role.users);
        Scope.apps = angular.copy(Scope.role.apps);
		Scope.keyData = Scope.role.lookup_keys;
        $('#save_button').hide();
        $('#update_button').show();
        $("tr.info").removeClass('info');
        $('#row_' + Scope.role.id).addClass('info');
    }
    $scope.makeDefault = function(){
        Scope.role.default_app_id = this.app.id;
    };
    $scope.clearDefault = function(){
        Scope.role.default_app_id = null;
    };

    $("#key-value").keyup(function (event) {
        if (event.keyCode == 13) {

            $("#key-update").click();
        }
    });
};
import "../datatables";
import PresentationModal from "../scripts/presentation-modal.js"


//== Class definition
var ModuleDashboard = function() {
	
	var onFirstLoad = function() {
		var dialogue = new PresentationModal('#dialogueModal', '.dialogue-text');
		dialogue.open();
		dialogue.type("dialogue1", "Welcome to the Overview screen!");
	};

	var drawCharts = function() {
		$("#datatables-dashboard-products").DataTable({
	    pageLength: 6,
	    lengthChange: false,
	    bFilter: false,
	    autoWidth: false
	  });


	  // Pie chart
		new Chart(document.getElementById("chartjs-dashboard-pie"), {
			type: "pie",
			data: {
				labels: ["Leads", "Qualified", "Active", "At Risk"],
				datasets: [{
					data: [1602, 1253, 2465, 541],
					backgroundColor: [
						"#E8EAED",
						window.cssVariables.warning,
						window.cssVariables.primary,
						window.cssVariables.danger
					],
					borderWidth: 5,
					borderColor: "transparent"
					// borderColor: window.cssVariables.white
				}]
			},
			options: {
				responsive: !window.MSInputMethodContext,
				maintainAspectRatio: false,
				cutoutPercentage: 70,
				legend: {
					display: false
				}
			}
		});

	  new Chart(document.getElementById("chartjs-dashboard-bar"), {
	    type: "bar",
	    data: {
	      labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
	      datasets: [{
	        label: "Last year",
	        backgroundColor: window.cssVariables.primary,
	        borderColor: window.cssVariables.primary,
	        hoverBackgroundColor: window.cssVariables.primary,
	        hoverBorderColor: window.cssVariables.primary,
	        data: [54, 67, 41, 55, 62, 45, 55, 73, 60, 76, 48, 79],
	        barPercentage: .325,
	        categoryPercentage: .5
	      }, {
	        label: "This year",
	        backgroundColor: window.cssVariables["primary-light"],
	        borderColor: window.cssVariables["primary-light"],
	        hoverBackgroundColor: window.cssVariables["primary-light"],
	        hoverBorderColor: window.cssVariables["primary-light"],
	        data: [69, 66, 24, 48, 52, 51, 44, 53, 62, 79, 51, 68],
	        barPercentage: .325,
	        categoryPercentage: .5
	      }]
	    },
	    options: {
	      maintainAspectRatio: false,
	      cornerRadius: 15,
	      legend: {
	        display: false
	      },
	      scales: {
	        yAxes: [{
	          gridLines: {
	            display: false
	          },
	          ticks: {
	            stepSize: 20
	          },
	          stacked: true,
	        }],
	        xAxes: [{
	          gridLines: {
	            color: "transparent"
	          },
	          stacked: true,
	        }]
	      }
	    }
	  });
	};
	
	return {
        //main function to initiate the module
        init: function () {
        	
					drawCharts();
					// feather.replace();
					// onFirstLoad();

        }
    };
}();

jQuery(document).ready(function() {
    ModuleDashboard.init();
});

export default ModuleDashboard;
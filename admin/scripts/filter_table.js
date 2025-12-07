// function to filter table row based on Search Query
function filterTable() {
    // Get input value and convert to lowercase
    var input = document.getElementById("liveSearch").value.toLowerCase();
    // Get table rows
    var rows = document.getElementById("customer-table").getElementsByTagName("tr");

    // Loop through table rows
    for (var i = 1; i < rows.length; i++) {
      // Get cells in current row
      var cells = rows[i].getElementsByTagName("td");
      var found = false;
      // Loop through cells
      for (var j = 0; j < cells.length; j++) {
        // Check if cell text matches search query
        if (cells[j]) {
          var cellText = cells[j].textContent.toLowerCase();
          if (cellText.indexOf(input) > -1) {
            found = true;
            break;
          }
        }
      }
      // Show or hide row based on search result
      if (found) {
        rows[i].style.display = "";
      } else {
        rows[i].style.display = "none";
      }
    }
  }

  // Add event listener to input field
  document.getElementById("liveSearch").addEventListener("input", filterTable);
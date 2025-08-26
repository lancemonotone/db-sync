jQuery(document).ready(function ($) {
  console.log("*** Database Sync JavaScript loaded");

  // File polling variables
  var filePollingInterval;
  var isPolling = false;

  // Preset configurations
  var presets = {
    development: [
      "posts",
      "postmeta",
      "terms",
      "term_relationships",
      "term_taxonomy",
      "termmeta",
      "options",
      "widgets",
      "widget_areas",
      "users",
      "usermeta",
    ],
    content: ["posts", "postmeta", "terms", "term_relationships", "termmeta"],
  };

  var presetDescriptions = {
    development: "Full development environment sync",
    content: "Content and structure only",
    custom: "Select individual tables to sync",
  };

  // Handle preset button clicks
  $(".preset-btn").on("click", function () {
    var preset = $(this).data("preset");
    selectPreset(preset);
  });

  // Handle table checkbox changes
  $('input[name="tables[]"]').on("change", function () {
    detectPresetMatch();
  });

  // Select a preset
  function selectPreset(preset) {
    // Update button states
    $(".preset-btn").removeClass("active");
    $('[data-preset="' + preset + '"]').addClass("active");

    // Update description
    $("#preset-description").text(presetDescriptions[preset]);

    // Update checkboxes
    if (preset === "custom") {
      // Show all checkboxes, don't change selections
      $("#table-selection-container").show();
    } else {
      // Select preset tables
      $('input[name="tables[]"]').prop("checked", false);
      if (presets[preset]) {
        presets[preset].forEach(function (table) {
          $('input[name="tables[]"][value="' + table + '"]').prop(
            "checked",
            true
          );
        });
      }
      $("#table-selection-container").show();
    }
  }

  // Detect if current selection matches a preset
  function detectPresetMatch() {
    var selectedTables = [];
    $('input[name="tables[]"]:checked').each(function () {
      selectedTables.push($(this).val());
    });

    // Sort arrays for comparison
    selectedTables.sort();

    // Check if selection matches any preset
    for (var preset in presets) {
      var presetTables = presets[preset].slice().sort();
      if (JSON.stringify(selectedTables) === JSON.stringify(presetTables)) {
        selectPreset(preset);
        return;
      }
    }

    // No match found, select custom
    selectPreset("custom");
  }

  // Handle export button click
  $("#export-database").on("click", function (e) {
    console.log("*** Export button clicked");
    e.preventDefault();

    var $btn = $(this);
    var originalText = $btn.text();

    // Show loading state
    $btn.text(dbSyncAjax.strings.exporting).prop("disabled", true);

    // Collect form data
    var formData = new FormData();
    formData.append("action", "db_sync_export");
    formData.append("nonce", dbSyncAjax.nonce);

    // Get current preset
    var currentPreset = $(".preset-btn.active").data("preset");
    formData.append("preset", currentPreset);

    // Add custom tables if selected
    if (currentPreset === "custom") {
      $('input[name="tables[]"]:checked').each(function () {
        formData.append("tables[]", $(this).val());
      });
    }

    // Make AJAX request
    $.ajax({
      url: dbSyncAjax.ajaxurl,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      success: function (response) {
        if (response.success) {
          showResults(
            "Export completed successfully! File saved: " +
              response.data.filename +
              " (" +
              response.data.file_size +
              ")",
            "success"
          );

          // Refresh the import section to show the new file
          setTimeout(function () {
            location.reload();
          }, 2000);
        } else {
          showResults("Export failed: " + response.data, "error");
        }
      },
      error: function () {
        showResults("Export failed. Please try again.", "error");
      },
      complete: function () {
        $btn.text(originalText).prop("disabled", false);
      },
    });
  });

  // Handle file selection
  $(".file-item").on("click", function () {
    // Don't allow selection of backup files
    if ($(this).hasClass("backup-file")) {
      return;
    }

    $(".file-item").removeClass("selected");
    $(this).addClass("selected");
  });

  // Handle delete file button clicks
  $(document).on("click", ".delete-file-btn", function (e) {
    e.preventDefault();
    e.stopPropagation(); // Prevent file selection when clicking delete

    var $btn = $(this);
    var filename = $btn.data("filename");
    var $fileItem = $btn.closest(".file-item");
    var isBackup = $fileItem.hasClass("backup-file");
    var fileType = isBackup ? "backup file" : "SQL file";

    // Confirm deletion
    if (
      !confirm(
        "Are you sure you want to delete this " + fileType + "?\n\n" + filename
      )
    ) {
      return;
    }

    // Show loading state
    $btn.text("...").prop("disabled", true);

    // Collect form data
    var formData = new FormData();
    formData.append("action", "db_sync_delete");
    formData.append("nonce", dbSyncAjax.nonce);
    formData.append("filename", filename);

    // Make AJAX request
    $.ajax({
      url: dbSyncAjax.ajaxurl,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      success: function (response) {
        if (response.success) {
          // Remove the file item from the DOM
          $fileItem.fadeOut(300, function () {
            $(this).remove();

            // Check if there are any files left
            var remainingFiles = $(".file-item").length;
            if (remainingFiles === 0) {
              // Refresh the page to show "no files" message
              location.reload();
            }
          });

          showResults("File deleted successfully: " + filename, "success");
        } else {
          showResults("Delete failed: " + response.data, "error");
        }
      },
      error: function () {
        showResults("Delete failed. Please try again.", "error");
      },
      complete: function () {
        $btn.text("×").prop("disabled", false);
      },
    });
  });

  // Handle preview button
  $("#preview-import").on("click", function (e) {
    e.preventDefault();

    var $btn = $(this);
    var originalText = $btn.text();

    // Get selected file
    var selectedFile = $(".file-item.selected").data("filename");
    if (!selectedFile) {
      alert("Please select a SQL file first.");
      return;
    }

    // Show loading state
    $btn.text(dbSyncAjax.strings.previewing).prop("disabled", true);

    // Collect form data
    var formData = new FormData();
    formData.append("action", "db_sync_preview");
    formData.append("nonce", dbSyncAjax.nonce);
    formData.append("filename", selectedFile);

    // Make AJAX request
    $.ajax({
      url: dbSyncAjax.ajaxurl,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      success: function (response) {
        if (response.success) {
          showPreview(response.data);
        } else {
          showResults("Preview failed: " + response.data, "error");
        }
      },
      error: function () {
        showResults("Preview failed. Please try again.", "error");
      },
      complete: function () {
        $btn.text(originalText).prop("disabled", false);
      },
    });
  });

  // Handle import button
  $("#import-database").on("click", function (e) {
    e.preventDefault();

    var $btn = $(this);
    var originalText = $btn.text();

    // Get selected file
    var selectedFile = $(".file-item.selected").data("filename");
    if (!selectedFile) {
      alert("Please select a SQL file first.");
      return;
    }

    // Confirm import
    if (
      !confirm(
        "This will overwrite existing data. Are you sure you want to continue?"
      )
    ) {
      return;
    }

    // Show loading state
    $btn.text(dbSyncAjax.strings.importing).prop("disabled", true);

    // Collect form data
    var formData = new FormData();
    formData.append("action", "db_sync_import");
    formData.append("nonce", dbSyncAjax.nonce);
    formData.append("filename", selectedFile);

    // Make AJAX request
    $.ajax({
      url: dbSyncAjax.ajaxurl,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      success: function (response) {
        if (response.success) {
          var message =
            "Import completed successfully! Tables: " +
            response.data.tables_processed +
            ", Rows: " +
            response.data.rows_imported +
            ". SQL file has been deleted.";

          // Add backup information and restore button
          if (response.data.backup_file) {
            message +=
              " Backup created: " +
              response.data.backup_file.filename +
              " (" +
              response.data.backup_file.file_size +
              ", " +
              response.data.backup_file.tables_backed_up +
              " tables)";

            // Add restore button
            message +=
              '<br><br><button type="button" id="restore-backup" class="button button-secondary" data-backup="' +
              response.data.backup_file.filename +
              '">Restore Backup</button>';
          }

          showResults(message, "success");

          // Refresh the page to update file list
          setTimeout(function () {
            location.reload();
          }, 2000);
        } else {
          showResults("Import failed: " + response.data, "error");
        }
      },
      error: function () {
        showResults("Import failed. Please try again.", "error");
      },
      complete: function () {
        $btn.text(originalText).prop("disabled", false);
      },
    });
  });

  // Show results
  function showResults(message, type) {
    var $results = $("#db-sync-results");
    var $content = $("#results-content");

    $content.html(
      '<div class="notice notice-' + type + '"><p>' + message + "</p></div>"
    );
    $results.show();

    // Scroll to results
    $("html, body").animate(
      {
        scrollTop: $results.offset().top - 50,
      },
      500
    );
  }

  // Handle restore button (delegated event)
  $(document).on("click", "#restore-backup", function (e) {
    e.preventDefault();

    var $btn = $(this);
    var backupFilename = $btn.data("backup");
    var originalText = $btn.text();

    // Confirm restore
    if (
      !confirm(
        "This will restore the database to its state before the import. Are you sure you want to continue?"
      )
    ) {
      return;
    }

    // Show loading state
    $btn.text("Restoring...").prop("disabled", true);

    // Collect form data
    var formData = new FormData();
    formData.append("action", "db_sync_restore");
    formData.append("nonce", dbSyncAjax.nonce);
    formData.append("backup_filename", backupFilename);

    // Make AJAX request
    $.ajax({
      url: dbSyncAjax.ajaxurl,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      success: function (response) {
        if (response.success) {
          showResults(
            "Restore completed successfully! Tables: " +
              response.data.tables_processed +
              ", Rows: " +
              response.data.rows_imported +
              ". Backup file has been deleted.",
            "success"
          );

          // Refresh the page to update file list
          setTimeout(function () {
            location.reload();
          }, 2000);
        } else {
          showResults("Restore failed: " + response.data, "error");
        }
      },
      error: function () {
        showResults("Restore failed. Please try again.", "error");
      },
      complete: function () {
        $btn.text(originalText).prop("disabled", false);
      },
    });
  });

  // Show preview
  function showPreview(data) {
    var $results = $("#db-sync-results");
    var $content = $("#results-content");

    var html = '<div class="notice notice-info">';
    html += "<h3>Import Preview</h3>";
    html +=
      "<p><strong>Source URL:</strong> " +
      (data.source_url || "Not detected") +
      "</p>";
    html += "<p><strong>Target URL:</strong> " + data.target_url + "</p>";
    html +=
      "<p><strong>Total Rows:</strong> " +
      data.total_rows.toLocaleString() +
      "</p>";

    if (Object.keys(data.tables).length > 0) {
      html += "<h4>Tables to be imported:</h4><ul>";
      for (var table in data.tables) {
        html +=
          "<li>" +
          table +
          ": " +
          data.tables[table].toLocaleString() +
          " rows</li>";
      }
      html += "</ul>";
    }

    html += "</div>";

    $content.html(html);
    $results.show();

    // Scroll to results
    $("html, body").animate(
      {
        scrollTop: $results.offset().top - 50,
      },
      500
    );
  }

  // Start file polling
  function startFilePolling() {
    if (isPolling) {
      return; // Already polling
    }

    console.log("*** Starting file polling");
    isPolling = true;

    // Check immediately
    checkForFileChanges();

    // Then check every 5 seconds
    filePollingInterval = setInterval(function () {
      checkForFileChanges();
    }, 5000);
  }

  // Stop file polling
  function stopFilePolling() {
    if (!isPolling) {
      return; // Not polling
    }

    console.log("*** Stopping file polling");
    isPolling = false;

    if (filePollingInterval) {
      clearInterval(filePollingInterval);
      filePollingInterval = null;
    }
  }

  // Check for file changes
  function checkForFileChanges() {
    $.ajax({
      url: dbSyncAjax.ajaxurl,
      type: "POST",
      data: {
        action: "db_sync_check_files",
        nonce: dbSyncAjax.nonce,
      },
      success: function (response) {
        if (response.success) {
          console.log("*** File check response:", response.data);

          // Always update file list if files are present, regardless of change detection
          if (response.data.files && response.data.files.length > 0) {
            console.log("*** Files detected, updating file list");
            updateFileList(response.data.files);
          } else if (response.data.changed) {
            console.log("*** File changes detected, updating file list");
            updateFileList(response.data.files);
          }
        }
      },
      error: function (xhr, status, error) {
        console.log("*** File polling error:", error);
      },
    });
  }

  // Update file list in the DOM
  function updateFileList(files) {
    console.log("*** updateFileList called with files:", files);

    var $fileList = $(".file-list");
    var $noFilesMessage = $(".no-files-message");

    console.log("*** Found file list element:", $fileList.length);
    console.log("*** Found no files message element:", $noFilesMessage.length);

    if (files.length === 0) {
      // No files, show message
      $fileList.empty();
      $noFilesMessage.show();
      return;
    }

    // Hide no files message
    $noFilesMessage.hide();

    // Clear existing files
    $fileList.empty();

    // Separate import files and backup files
    var importFiles = [];
    var backupFiles = [];

    files.forEach(function (file) {
      console.log(
        "*** Processing file:",
        file.filename,
        "is_backup:",
        file.is_backup
      );
      if (file.is_backup) {
        backupFiles.push(file);
      } else {
        importFiles.push(file);
      }
    });

    console.log(
      "*** Import files:",
      importFiles.length,
      "Backup files:",
      backupFiles.length
    );

    // Add import files
    importFiles.forEach(function (file, index) {
      var fileHtml = createFileItemHtml(file, index === 0);
      $fileList.append(fileHtml);
    });

    // Add backup files
    backupFiles.forEach(function (file) {
      var fileHtml = createFileItemHtml(file, false, true);
      $fileList.append(fileHtml);
    });

    // Re-attach event handlers
    attachFileEventHandlers();

    // Show notification
    showResults("File list updated - new files detected", "success");
  }

  // Create HTML for file item
  function createFileItemHtml(file, isSelected, isBackup) {
    var selectedClass = isSelected ? "selected" : "";
    var backupClass = isBackup ? "backup-file" : "";
    var fileDate = new Date(file.modified * 1000).toLocaleString();

    var html =
      '<div class="file-item ' +
      selectedClass +
      " " +
      backupClass +
      '" data-filename="' +
      file.filename +
      '">';
    html += '<div class="file-info">';
    html += '<div class="file-name">' + file.filename + "</div>";
    html += '<div class="file-details">';
    html += '<span class="file-preset">' + file.preset + "</span>";
    html += '<span class="file-environment">' + file.environment + "</span>";
    html += '<span class="file-size">' + file.file_size + "</span>";
    html += '<span class="file-date">' + fileDate + "</span>";
    if (isBackup) {
      html += '<span class="file-type">Backup</span>';
    }
    html += "</div>";
    html += "</div>";
    html +=
      '<button type="button" class="delete-file-btn" data-filename="' +
      file.filename +
      '" title="Delete ' +
      (isBackup ? "backup file" : "SQL file") +
      '">×</button>';
    html += "</div>";

    return html;
  }

  // Re-attach event handlers to new file items
  function attachFileEventHandlers() {
    // File selection
    $(".file-item")
      .off("click")
      .on("click", function () {
        // Don't allow selection of backup files
        if ($(this).hasClass("backup-file")) {
          return;
        }

        $(".file-item").removeClass("selected");
        $(this).addClass("selected");
      });
  }

  // Start polling when page loads
  startFilePolling();

  // Stop polling when page is hidden (tab switch, etc.)
  $(document).on("visibilitychange", function () {
    if (document.hidden) {
      stopFilePolling();
    } else {
      startFilePolling();
    }
  });
});

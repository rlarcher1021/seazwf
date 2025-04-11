<?php
/*
 * File: footer.php
 * Path: /includes/footer.php
 * Created: [Original Date]
 * Author: [Your Name] // Updated Author: Robert Archer
 * Updated: 2025-04-08 - Added Site Selection Modal HTML.
 *
 * Description: Site footer, closing tags, JS includes, copyright, modals.
 */

 // Ensure $pdo is available if needed for modal content generation
 // It should be available if included after db_connect.php in the main script
 // Using global might be necessary if $pdo was not passed or included in a function scope.
 global $pdo;

$current_page_basename = basename($_SERVER['PHP_SELF']);
?>

        </div> <!-- Close content-wrapper OR content-wrapper-full (opened in header.php) -->

    <?php // Only close main-content if sidebar was shown (it's outside the wrapper then)
    if ($current_page_basename !== 'index.php' && $current_page_basename !== 'checkin.php'):
    ?>
    </main> <!-- Close main-content (opened in header.php) -->
    <?php endif; ?>

</div> <!-- Close app-container (opened in header.php) -->


<!-- =============================================================== -->
<!--                MODALS (Add required modals here)                -->
<!-- =============================================================== -->

<!-- START: Site Selection Modal -->
<div class="modal fade" id="selectSiteModal" tabindex="-1" role="dialog" aria-labelledby="selectSiteModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="selectSiteModalLabel">Select Site for Manual Check-in</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Please choose the site you wish to perform a manual check-in for:</p>
                <ul class="list-group">
                    <?php
                    // Fetch active sites FOR THE MODAL
                    // Use a separate query here to ensure it always gets all active sites
                    // regardless of user's current filter or impersonation state.
                    $modal_sites = []; // Initialize array
                    try {
                        // Check if $pdo is valid before using it
                        if (isset($pdo) && $pdo instanceof PDO) {
                            $stmt_modal_sites = $pdo->query("SELECT id, name FROM sites WHERE is_active = TRUE ORDER BY name ASC");
                            if ($stmt_modal_sites) { // Check if query preparation was successful
                                $modal_sites = $stmt_modal_sites->fetchAll(PDO::FETCH_ASSOC);
                            } else {
                                error_log("Footer Modal Error: Failed to prepare site query.");
                            }
                        } else {
                             error_log("Footer Modal Error: PDO object not available or invalid.");
                        }
                    } catch (PDOException $e) { // Catch PDO specific exceptions
                        error_log("Footer Modal PDOException - Fetching sites: ".$e->getMessage());
                    } catch (Exception $e) { // Catch other potential exceptions
                        error_log("Footer Modal General Exception - Fetching sites: ".$e->getMessage());
                    }

                    // Display the fetched sites or an error message
                    if (!empty($modal_sites)) {
                        foreach ($modal_sites as $site) {
                            // Each list item links directly to checkin.php with the manual_site_id
                            echo '<li class="list-group-item list-group-item-action">';
                            // Link itself, covers the whole list item for easier clicking
                            echo '<a href="checkin.php?manual_site_id=' . htmlspecialchars($site['id']) . '" class="stretched-link" style="text-decoration: none; color: inherit;">';
                            echo htmlspecialchars($site['name']);
                            echo '</a></li>';
                        }
                    } else {
                        echo '<li class="list-group-item">No active sites found or database error occurred.</li>';
                    }
                    ?>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
<!-- END: Site Selection Modal -->

<!-- Add other modals here if needed -->

<!-- =============================================================== -->
<!--                 END MODALS                                      -->
<!-- =============================================================== -->


<!-- JavaScript Includes -->
<!-- Google Translate Script (Keep if needed) -->
<script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

<!-- Main Custom JS File (Uncomment if you create/need it) -->
<!-- <script src="assets/js/main.js?v=<?php // echo filemtime(__DIR__ . '/../assets/js/main.js'); // Path relative to /includes/ ?>"></script> -->


<!-- Footer Copyright -->
<footer style="text-align: center; padding: 15px; font-size: 12px; color: var(--color-gray, #6B7280); margin-top: 20px; border-top: 1px solid var(--color-border, #E5E7EB);">
    © <?php echo date("Y"); ?> Arizona@Work - Southeastern Arizona. All Rights Reserved.
</footer>

!-- Bootstrap JS Dependencies - IMPORTANT: Keep this order -->

<!-- 1. jQuery (Correct CDN) -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>

<!-- 2. Bootstrap Bundle JS (Includes Popper.js) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-LtrjvnR4Twt/qOuYxE721u19sVFLVSA4hf/rRt6PrZTmiPltdZcI7q7PXQBYTKyf" crossorigin="anonymous"></script>
<!-- Any page-specific scripts loaded AFTER Bootstrap should go here -->

</body>
</html>
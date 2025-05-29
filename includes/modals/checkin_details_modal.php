<!-- Check-in Details Modal -->
<div class="modal fade" id="checkinDetailsModal" tabindex="-1" role="dialog" aria-labelledby="checkinDetailsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="checkinDetailsModalLabel">Check-in Details</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="modalCheckInId" name="modalCheckInId" value="">
        <div class="modal-body-content">
          <!-- Content will be populated here by JavaScript -->
          <p class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading details...</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="saveCheckinAnswersButton">Save Changes</button>
      </div>
    </div>
  </div>
</div>
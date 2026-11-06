<script>
document.addEventListener('DOMContentLoaded', function () {
    const policyId = @json($policy->id);
    const csrfToken = @json(csrf_token());
    const today = @json(now()->toDateString());
    const uploadButton = document.getElementById('uploadPolicyPdfBtn');
    const uploadInput = document.getElementById('uploadPolicyPdfInput');

    if (uploadButton && uploadInput) {
        uploadButton.addEventListener('click', () => uploadInput.click());

        uploadInput.addEventListener('change', async function () {
            const file = this.files && this.files[0];
            if (!file) return;

            const isPdf = file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf');
            if (!isPdf || file.size > 20 * 1024 * 1024) {
                Swal.fire('Invalid PDF', 'Select a PDF file up to 20 MB.', 'error');
                this.value = '';
                return;
            }

            const commentPrompt = await Swal.fire({
                title: 'PDF Details',
                html: `
                    <label for="policyPdfDate" class="form-label d-block text-start fw-semibold">Document Date</label>
                    <input type="date" id="policyPdfDate" class="form-control mb-3" value="${today}" min="${today}">
                    <label for="policyPdfComment" class="form-label d-block text-start fw-semibold">Comment <span class="text-muted fw-normal">(Optional)</span></label>
                    <textarea id="policyPdfComment" class="form-control" rows="4" maxlength="2000" placeholder="Enter a note about this policy PDF..."></textarea>
                `,
                showCancelButton: true,
                confirmButtonText: 'Upload PDF',
                focusConfirm: false,
                preConfirm: () => {
                    const documentDate = document.getElementById('policyPdfDate').value;
                    if (!documentDate) {
                        Swal.showValidationMessage('Please select the document date.');
                        return false;
                    }
                    if (documentDate < today) {
                        Swal.showValidationMessage('Past dates cannot be selected.');
                        return false;
                    }
                    return {
                        documentDate,
                        comment: document.getElementById('policyPdfComment').value.trim()
                    };
                }
            });
            if (!commentPrompt.isConfirmed) {
                this.value = '';
                return;
            }

            const formData = new FormData();
            formData.append('_token', csrfToken);
            formData.append('policy_id', policyId);
            formData.append('document', file);
            formData.append('comment', commentPrompt.value.comment);
            formData.append('document_date', commentPrompt.value.documentDate);

            Swal.fire({ title: 'Uploading PDF...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            try {
                const response = await fetch(@json(route('policies.legacy.documents.upload')), {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json' }
                });
                const result = await response.json();
                if (!response.ok || result.status !== true) throw new Error(result.message || 'Upload failed.');

                await Swal.fire('Uploaded', result.message || 'Policy PDF uploaded successfully.', 'success');
                window.location.reload();
            } catch (error) {
                Swal.fire('Upload failed', error.message, 'error');
                uploadInput.value = '';
            }
        });
    }

    document.querySelectorAll('.delete-policy-pdf').forEach(function (button) {
        button.addEventListener('click', async function () {
            const confirmation = await Swal.fire({
                title: 'Delete this PDF?',
                text: this.dataset.fileName + ' will be permanently removed.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Delete PDF',
                confirmButtonColor: '#dc3545'
            });
            if (!confirmation.isConfirmed) return;

            const formData = new FormData();
            formData.append('_token', csrfToken);
            formData.append('policy_id', policyId);
            formData.append('document_id', this.dataset.documentId);

            try {
                const response = await fetch(@json(route('policies.legacy.documents.delete')), {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json' }
                });
                const result = await response.json();
                if (!response.ok || result.status !== 'success') throw new Error(result.message || 'Delete failed.');

                await Swal.fire('Deleted', result.message, 'success');
                window.location.reload();
            } catch (error) {
                Swal.fire('Delete failed', error.message, 'error');
            }
        });
    });
});
</script>



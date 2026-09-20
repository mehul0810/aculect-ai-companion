import { Button } from '@wordpress/components';

export function memoryRecordsList( memoryRecords ) {
	return Array.isArray( memoryRecords?.items ) ? memoryRecords.items : [];
}

export function memoryRecordsSummary( memoryRecords ) {
	return memoryRecords?.summary && typeof memoryRecords.summary === 'object'
		? memoryRecords.summary
		: {};
}

function memoryReviewPageUrl( pageNumber ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'page', 'aculect-ai-companion' );
	url.searchParams.set( 'tab', 'learning' );
	url.searchParams.set( 'learning_surface', 'memory' );
	url.searchParams.set( 'memory_page', String( Math.max( 1, pageNumber ) ) );
	return url.toString();
}

/**
 * Render bounded navigation for the memory review list.
 *
 * @param {Object} props            Component properties.
 * @param {number} props.page       Current page.
 * @param {number} props.totalPages Total page count.
 */
export function MemoryPagination( { page, totalPages } ) {
	if ( totalPages <= 1 ) {
		return null;
	}

	return (
		<div className="aculect-ai-companion-learning-pagination">
			<Button
				variant="secondary"
				disabled={ page <= 1 }
				href={ memoryReviewPageUrl( page - 1 ) }
			>
				Previous
			</Button>
			<span>
				Page { page } of { totalPages }
			</span>
			<Button
				variant="secondary"
				disabled={ page >= totalPages }
				href={ memoryReviewPageUrl( page + 1 ) }
			>
				Next
			</Button>
		</div>
	);
}

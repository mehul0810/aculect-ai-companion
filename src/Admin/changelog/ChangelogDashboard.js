import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { EmptyState } from '../shared/EmptyState';
import { isPlainObject, safeExternalUrl } from '../shared/value-utils.mjs';
const CHANGELOG_METADATA_KEYS = new Set( [
	'date',
	'releaseDate',
	'releasedAt',
	'type',
] );
function versionParts( version ) {
	return String( version || '' )
		.split( '.' )
		.map( ( part ) => Number.parseInt( part, 10 ) || 0 );
}

function compareVersionsDescending( firstVersion, secondVersion ) {
	const firstParts = versionParts( firstVersion );
	const secondParts = versionParts( secondVersion );
	const length = Math.max( firstParts.length, secondParts.length );

	for ( let index = 0; index < length; index += 1 ) {
		const firstPart = firstParts[ index ] || 0;
		const secondPart = secondParts[ index ] || 0;

		if ( firstPart !== secondPart ) {
			return secondPart - firstPart;
		}
	}

	return String( secondVersion ).localeCompare( String( firstVersion ) );
}

function releaseType( version ) {
	const parts = versionParts( version );
	const patch = parts[ 2 ] || 0;

	if ( patch > 0 ) {
		return 'Patch release';
	}

	const major = parts[ 0 ] || 0;
	const minor = parts[ 1 ] || 0;

	if ( major > 0 && minor === 0 ) {
		return 'Major release';
	}

	return 'Minor release';
}

function normalizeChangelogEntries( changelog ) {
	const entries = Object.entries(
		isPlainObject( changelog ) ? changelog : {}
	)
		.map( ( [ version, entry ] ) => {
			const entryData = isPlainObject( entry ) ? entry : {};
			const date = String(
				entryData.date ||
					entryData.releaseDate ||
					entryData.releasedAt ||
					''
			).trim();
			const type = String( entryData.type || '' ).trim();

			return {
				version,
				type: type || releaseType( version ),
				date,
				groups: Object.entries( entryData )
					.filter(
						( [ title ] ) => ! CHANGELOG_METADATA_KEYS.has( title )
					)
					.map( ( [ title, items ] ) => ( {
						title,
						items: Array.isArray( items )
							? items.filter( ( item ) =>
									String( item || '' ).trim()
							  )
							: [],
					} ) )
					.filter( ( group ) => group.items.length > 0 ),
			};
		} )
		.filter( ( entry ) => entry.version );

	return entries.sort( ( firstEntry, secondEntry ) =>
		compareVersionsDescending( firstEntry.version, secondEntry.version )
	);
}

export function ChangelogDashboard( { changelog, metadata } ) {
	const pluginMetadata = isPlainObject( metadata ) ? metadata : {};
	const entries = normalizeChangelogEntries( changelog );
	const latestVersion = entries[ 0 ]?.version || '';
	const installedVersion =
		pluginMetadata.version || pluginMetadata.stableTag || latestVersion;
	const [ selectedVersion, setSelectedVersion ] = useState(
		latestVersion || installedVersion || ''
	);
	const selectedEntry =
		entries.find( ( entry ) => entry.version === selectedVersion ) ||
		entries[ 0 ];
	const wordpressOrgUrl = safeExternalUrl( pluginMetadata.wordpressOrgUrl );
	const supportUrl = safeExternalUrl( pluginMetadata.supportUrl );
	const reviewUrl = safeExternalUrl( pluginMetadata.reviewUrl );
	const releaseDate = selectedEntry?.date || 'Not listed in changelog';
	const metadataRows = [
		{ label: 'Version', value: selectedEntry?.version || '-' },
		{ label: 'Release date', value: releaseDate },
		{ label: 'Type', value: selectedEntry?.type || 'Release' },
		{ label: 'Tested up to', value: pluginMetadata.testedUpTo || '-' },
		{ label: 'Requires WP', value: pluginMetadata.requiresAtLeast || '-' },
		{ label: 'Requires PHP', value: pluginMetadata.requiresPhp || '-' },
	];

	if ( entries.length === 0 ) {
		return (
			<div className="aculect-ai-companion-changelog-dashboard">
				<EmptyState title="No changelog entries">
					Check the bundled changelog file or the WordPress.org
					developer tab for release notes.
				</EmptyState>
			</div>
		);
	}

	return (
		<div className="aculect-ai-companion-changelog-dashboard">
			{ wordpressOrgUrl && (
				<div className="aculect-ai-companion-tab-actions">
					<Button
						href={ wordpressOrgUrl }
						target="_blank"
						rel="noreferrer noopener"
						variant="secondary"
					>
						WordPress.org Changelog
					</Button>
				</div>
			) }

			<div className="aculect-ai-companion-changelog-layout">
				<aside className="aculect-ai-companion-changelog-sidebar">
					<h3 className="aculect-ai-companion-changelog-sidebar__title">
						Versions
					</h3>
					<div className="aculect-ai-companion-changelog-version-list">
						{ entries.map( ( entry ) => {
							const isSelected =
								entry.version === selectedEntry.version;

							return (
								<button
									key={ entry.version }
									type="button"
									className={
										isSelected ? 'is-selected' : ''
									}
									aria-pressed={ isSelected }
									onClick={ () =>
										setSelectedVersion( entry.version )
									}
								>
									<span className="aculect-ai-companion-changelog-version-list__version">
										{ entry.version }
									</span>
									<span className="aculect-ai-companion-changelog-version-list__meta">
										{ entry.date || 'Date not listed' }
									</span>
									<span className="aculect-ai-companion-changelog-version-list__badges">
										{ entry.version === latestVersion && (
											<em>Latest</em>
										) }
										{ entry.version ===
											installedVersion && (
											<em>Installed</em>
										) }
									</span>
								</button>
							);
						} ) }
					</div>
				</aside>

				<section className="aculect-ai-companion-changelog-detail">
					<div className="aculect-ai-companion-changelog-detail__header">
						<div>
							<span className="aculect-ai-companion-eyebrow">
								Selected release
							</span>
							<h3 className="aculect-ai-companion-changelog-detail__version">
								{ selectedEntry.version }
							</h3>
						</div>
						<div className="aculect-ai-companion-changelog-detail__badges">
							{ selectedEntry.version === latestVersion && (
								<span className="aculect-ai-companion-changelog-detail__badge">
									Latest
								</span>
							) }
							{ selectedEntry.version === installedVersion && (
								<span className="aculect-ai-companion-changelog-detail__badge">
									Installed
								</span>
							) }
						</div>
					</div>

					<div className="aculect-ai-companion-changelog-meta-grid">
						{ metadataRows.map( ( item ) => (
							<div
								key={ item.label }
								className="aculect-ai-companion-changelog-meta-grid__item"
							>
								<span className="aculect-ai-companion-changelog-meta-grid__label">
									{ item.label }
								</span>
								<strong className="aculect-ai-companion-changelog-meta-grid__value">
									{ item.value }
								</strong>
							</div>
						) ) }
					</div>

					{ selectedEntry.groups.length > 0 ? (
						<div className="aculect-ai-companion-changelog-notes">
							{ selectedEntry.groups.map( ( group ) => (
								<section
									key={ group.title }
									className="aculect-ai-companion-changelog-notes__group"
								>
									<h4 className="aculect-ai-companion-changelog-notes__title">
										{ group.title }
									</h4>
									<ul className="aculect-ai-companion-changelog-notes__list">
										{ group.items.map( ( item, index ) => (
											<li
												key={ `${ selectedEntry.version }-${ group.title }-${ index }` }
											>
												{ item }
											</li>
										) ) }
									</ul>
								</section>
							) ) }
						</div>
					) : (
						<EmptyState title="No release notes">
							This version exists in the changelog source, but no
							grouped notes were found.
						</EmptyState>
					) }
				</section>
			</div>

			<div className="aculect-ai-companion-changelog-help">
				<div className="aculect-ai-companion-changelog-help__item">
					<h3 className="aculect-ai-companion-changelog-help__title">
						Need help with an update?
					</h3>
					<p className="aculect-ai-companion-changelog-help__copy">
						Use the support forum for release questions,
						compatibility reports, or setup issues.
					</p>
					{ supportUrl && (
						<a
							href={ supportUrl }
							target="_blank"
							rel="noreferrer noopener"
						>
							Open support forum
						</a>
					) }
				</div>
				<div className="aculect-ai-companion-changelog-help__item">
					<h3 className="aculect-ai-companion-changelog-help__title">
						Share release feedback
					</h3>
					<p className="aculect-ai-companion-changelog-help__copy">
						Reviews help prioritize improvements and surface
						compatibility feedback for other WordPress users.
					</p>
					{ reviewUrl && (
						<a
							href={ reviewUrl }
							target="_blank"
							rel="noreferrer noopener"
						>
							Leave a review
						</a>
					) }
				</div>
			</div>
		</div>
	);
}

<%--
    Project template override required to make the "Content Export" tab appear as a genuine
    peer of Content/Settings/History (see CMSPageContentExportController's class doc for why
    this can't be done any other way — the native tab strip is a hardcoded 3-<li> block in
    silverstripe/cms's own CMSMain_Content.ss, with no extension point to add a 4th).

    Copy this file to your project's own template resolution path, e.g.:
      app/templates/SilverStripe/CMS/Controllers/Includes/CMSMain_Content.ss

    It is NOT shipped active inside this module: SilverStripe always resolves a project's own
    templates ahead of a module's, never the reverse, so a module cannot make this override
    "just work" by shipping it in its own templates/ folder — only the consuming project can.

    This is a copy of silverstripe/cms's own CMSMain_Content.ss (as of the version installed
    when this was written) with exactly one addition: the "Content Export" <li>, gated by
    $HasContentExport and linked via $LinkPageContentExport (both supplied by
    MadeCurious\SiteTreeImportExport\Extensions\CMSMainContentExportTabExtension, already wired
    onto CMSMain by this module — no further PHP changes needed once this template is copied
    in). Pattern confirmed working via andrewandante/silverstripe-clippy, which adds a "User
    Guides" tab to this exact same template the exact same way.

    Maintenance note: if silverstripe/cms changes this template in a future release, this
    override will need to be manually reconciled — that's the accepted cost of the only
    available way to get a true 4th top-level tab (see CMSPageContentExportController's class
    doc for the alternative that needs no override at all).
--%>
<% if $CurrentRecord %>
<div id="pages-controller-cms-content" class="has-panel cms-content flexbox-area-grow fill-width fill-height $BaseCSSClasses" data-layout-type="border" data-pjax-fragment="Content" data-ignore-tab-state="true">
    $Tools
	<div class="fill-height flexbox-area-grow">
		<div class="cms-content-header north">
			<div class="cms-content-header-info flexbox-area-grow vertical-align-items">
				<% include SilverStripe\\Admin\\BackLink_Button Backlink=$BreadcrumbsBacklink %>
				<% include SilverStripe\\Admin\\CMSBreadcrumbs %>
			</div>

			<div class="cms-content-header-tabs cms-tabset">
				<ul class="cms-tabset-nav-primary nav nav-tabs">
					<li class="nav-item content-treeview<% if $TabIdentifier == 'edit' %> ui-tabs-active<% end_if %>">
						<a href="$LinkRecordEdit" class="nav-link cms-panel-link" title="Form_EditForm" data-href="$LinkRecordEdit">
							<%t SilverStripe\\CMS\\Controllers\\CMSMain.TabContent 'Content' %>
						</a>
					</li>
					<% if $LinkRecordSettings %>
					<li class="nav-item content-listview<% if $TabIdentifier == 'settings' %> ui-tabs-active<% end_if %>">
						<a href="$LinkRecordSettings" class="nav-link cms-panel-link" title="Form_EditForm" data-href="$LinkRecordSettings">
							<%t SilverStripe\\CMS\\Controllers\\CMSMain.TabSettings 'Settings' %>
						</a>
					</li>
					<% end_if %>
					<li class="nav-item content-listview<% if $TabIdentifier == 'history' %> ui-tabs-active<% end_if %>">
						<a href="$LinkRecordHistory" class="nav-link cms-panel-link" title="Form_EditForm" data-href="$LinkRecordHistory">
							<%t SilverStripe\\CMS\\Controllers\\CMSMain.TabHistory 'History' %>
						</a>
					</li>
                    <% if $HasContentExport %>
                    <li class="nav-item content-listview<% if $TabIdentifier == 'contentexport' %> ui-tabs-active<% end_if %>">
                        <a href="$LinkPageContentExport" class="nav-link cms-panel-link" title="Form_EditForm" data-href="$LinkPageContentExport">
                            <%t MadeCurious\\SiteTreeImportExport\\Extensions\\CMSMainContentExportTabExtension.TabContentExport 'Content Export' %>
                        </a>
                    </li>
                    <% end_if %>
				</ul>
			</div>
		</div>

		<div class="flexbox-area-grow fill-height">
			$EditForm
		</div>
	</div>
</div>
<% else %>
<div id="pages-controller-cms-content" class="flexbox-area-grow fill-height cms-content $BaseCSSClasses" data-layout-type="border" data-pjax-fragment="Content">
    <% include SilverStripe\\CMS\\Controllers\\CMSMain_LeftPanel %>
</div>
<% end_if %>

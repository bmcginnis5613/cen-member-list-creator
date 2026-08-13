(function () {
	'use strict';

	const groupSelect = document.getElementById('cen-mlv-groups');
	const loadButton = document.getElementById('cen-mlv-load-groups');
	const clearButton = document.getElementById('cen-mlv-clear');
	const loadStatus = document.getElementById('cen-mlv-load-status');
	const searchInput = document.getElementById('cen-mlv-search');
	const searchResults = document.getElementById('cen-mlv-search-results');
	const memberBody = document.getElementById('cen-mlv-members');
	const countLabel = document.getElementById('cen-mlv-count');
	const companyWarning = document.getElementById('cen-mlv-company-warning');
	const descriptionWarning = document.getElementById('cen-mlv-description-warning');
	const downloadButton = document.getElementById('cen-mlv-download');
	const downloadForm = document.getElementById('cen-mlv-download-form');
	const downloadFields = document.getElementById('cen-mlv-download-fields');

	if (!groupSelect || !memberBody || !window.CEN_MLV) {
		return;
	}

	let groupMembers = new Map();
	let manualMembers = new Map();
	let excludedIds = new Set();
	let searchTimer;
	let searchRequest = 0;
	let loadRequest = 0;

	function selectedGroupNumbers() {
		return Array.from(groupSelect.selectedOptions).map(function (option) {
			return String(option.value);
		});
	}

	function combinedMembers() {
		const combined = new Map();
		groupMembers.forEach(function (member, id) {
			if (!excludedIds.has(id)) combined.set(id, member);
		});
		manualMembers.forEach(function (member, id) {
			if (excludedIds.has(id)) return;
			if (combined.has(id)) {
				const existing = combined.get(id);
				existing.groups = Array.from(new Set((existing.groups || []).concat(member.groups || [])));
			} else {
				combined.set(id, member);
			}
		});

		return Array.from(combined.values()).sort(function (left, right) {
			const leftMissingFields = Number(!left.company) + Number(!left.description);
			const rightMissingFields = Number(!right.company) + Number(!right.description);
			if (leftMissingFields !== rightMissingFields) return rightMissingFields - leftMissingFields;
			const companyOrder = (left.company || '').localeCompare(right.company || '', undefined, { sensitivity: 'base', numeric: true });
			return companyOrder || (left.name || '').localeCompare(right.name || '', undefined, { sensitivity: 'base', numeric: true });
		});
	}

	function closeSearchResults() {
		searchResults.hidden = true;
		searchResults.innerHTML = '';
	}

	function makeTextCell(value, className) {
		const cell = document.createElement('td');
		if (className) cell.className = className;
		cell.textContent = value || '—';
		return cell;
	}

	function renderMembers() {
		const members = combinedMembers();
		memberBody.innerHTML = '';
		downloadFields.innerHTML = '';

		if (!members.length) {
			const row = document.createElement('tr');
			row.className = 'cen-mlv-empty-row';
			const cell = document.createElement('td');
			cell.colSpan = 6;
			cell.textContent = CEN_MLV.strings.empty;
			row.appendChild(cell);
			memberBody.appendChild(row);
			countLabel.textContent = CEN_MLV.strings.empty;
			companyWarning.hidden = true;
			descriptionWarning.hidden = true;
			downloadButton.disabled = true;
			return;
		}

		members.forEach(function (member) {
			const row = document.createElement('tr');
			row.dataset.memberId = member.id;

			const userIdCell = document.createElement('td');
			userIdCell.className = 'cen-mlv-user-id-column';
			if (member.editUrl) {
				const editLink = document.createElement('a');
				editLink.href = member.editUrl;
				editLink.textContent = member.id;
				editLink.title = CEN_MLV.strings.editUser.replace('%d', member.id);
				editLink.setAttribute('aria-label', editLink.title);
				userIdCell.appendChild(editLink);
			} else {
				userIdCell.textContent = member.id;
			}
			row.appendChild(userIdCell);

			const memberCell = document.createElement('td');
			const memberName = document.createElement('strong');
			memberName.textContent = member.name || '';
			memberCell.appendChild(memberName);
			if (member.email) {
				const email = document.createElement('span');
				email.className = 'cen-mlv-email';
				email.textContent = member.email;
				memberCell.appendChild(email);
			}
			row.appendChild(memberCell);
			row.appendChild(makeTextCell(member.company, member.company ? '' : 'cen-mlv-missing-company'));
			row.appendChild(makeTextCell(member.description, member.description ? 'cen-mlv-description' : 'cen-mlv-description cen-mlv-missing-description'));

			const source = member.groups && member.groups.length ? member.groups.join(', ') : CEN_MLV.strings.addedIndividually;
			row.appendChild(makeTextCell(source, 'cen-mlv-source'));

			const actionCell = document.createElement('td');
			actionCell.className = 'cen-mlv-remove-column';
			const removeButton = document.createElement('button');
			removeButton.type = 'button';
			removeButton.className = 'button button-small cen-mlv-remove';
			removeButton.textContent = CEN_MLV.strings.remove;
			removeButton.addEventListener('click', function () {
				const id = String(member.id);
				excludedIds.add(id);
				manualMembers.delete(id);
				renderMembers();
			});
			actionCell.appendChild(removeButton);
			row.appendChild(actionCell);
			memberBody.appendChild(row);

			const field = document.createElement('input');
			field.type = 'hidden';
			field.name = 'member_ids[]';
			field.value = member.id;
			downloadFields.appendChild(field);
		});

		selectedGroupNumbers().forEach(function (groupNumber) {
			const field = document.createElement('input');
			field.type = 'hidden';
			field.name = 'group_numbers[]';
			field.value = groupNumber;
			downloadFields.appendChild(field);
		});

		countLabel.textContent = members.length === 1
			? CEN_MLV.strings.oneMember
			: CEN_MLV.strings.manyMembers.replace('%d', members.length);
		const missingCompanies = members.filter(function (member) { return !member.company; }).length;
		if (missingCompanies) {
			companyWarning.textContent = missingCompanies === 1
				? CEN_MLV.strings.oneMissingCompany
				: CEN_MLV.strings.manyMissingCompany.replace('%d', missingCompanies);
			companyWarning.hidden = false;
		} else {
			companyWarning.hidden = true;
		}
		const missingDescriptions = members.filter(function (member) { return !member.description; }).length;
		if (missingDescriptions) {
			descriptionWarning.textContent = missingDescriptions === 1
				? CEN_MLV.strings.oneMissingDescription
				: CEN_MLV.strings.manyMissingDescription.replace('%d', missingDescriptions);
			descriptionWarning.hidden = false;
		} else {
			descriptionWarning.hidden = true;
		}
		downloadButton.disabled = false;
	}

	function requestJson(url) {
		return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(function (response) {
				return response.json().catch(function () { return null; }).then(function (payload) {
					if (!response.ok || !payload || !payload.success) {
						const message = payload && payload.data && payload.data.message ? payload.data.message : '';
						throw new Error(message);
					}
					return payload.data;
				});
			});
	}

	loadButton.addEventListener('click', function () {
		const groupNumbers = selectedGroupNumbers();
		if (!groupNumbers.length) {
			loadStatus.textContent = CEN_MLV.strings.chooseGroup;
			return;
		}

		const requestId = ++loadRequest;
		const url = new URL(CEN_MLV.ajaxUrl);
		url.searchParams.set('action', 'cen_mlv_load_members');
		url.searchParams.set('nonce', CEN_MLV.loadNonce);
		groupNumbers.forEach(function (groupNumber) { url.searchParams.append('group_numbers[]', groupNumber); });
		loadButton.disabled = true;
		loadStatus.textContent = CEN_MLV.strings.loading;

		requestJson(url).then(function (data) {
			if (requestId !== loadRequest) return;
			groupMembers = new Map();
			excludedIds = new Set();
			(data.members || []).forEach(function (member) { groupMembers.set(String(member.id), member); });
			loadStatus.textContent = '';
			renderMembers();
		}).catch(function (error) {
			if (requestId !== loadRequest) return;
			loadStatus.textContent = error.message || CEN_MLV.strings.loadError;
		}).finally(function () {
			if (requestId === loadRequest) loadButton.disabled = false;
		});
	});

	clearButton.addEventListener('click', function () {
		loadRequest++;
		searchRequest++;
		groupMembers = new Map();
		manualMembers = new Map();
		excludedIds = new Set();
		Array.from(groupSelect.options).forEach(function (option) { option.selected = false; });
		groupSelect.dispatchEvent(new Event('change', { bubbles: true }));
		searchInput.value = '';
		loadButton.disabled = false;
		loadStatus.textContent = '';
		closeSearchResults();
		renderMembers();
	});

	searchInput.addEventListener('input', function () {
		clearTimeout(searchTimer);
		const requestId = ++searchRequest;
		const term = searchInput.value.trim();
		if (term.length < 2) {
			closeSearchResults();
			return;
		}

		searchTimer = window.setTimeout(function () {
			const url = new URL(CEN_MLV.ajaxUrl);
			url.searchParams.set('action', 'cen_mlv_search_members');
			url.searchParams.set('nonce', CEN_MLV.searchNonce);
			url.searchParams.set('term', term);

			requestJson(url).then(function (members) {
				if (requestId !== searchRequest) return;
				searchResults.innerHTML = '';
				if (!members.length) {
					const empty = document.createElement('p');
					empty.className = 'cen-mlv-search-empty';
					empty.textContent = CEN_MLV.strings.noResults;
					searchResults.appendChild(empty);
				} else {
					const selected = new Set(combinedMembers().map(function (member) { return String(member.id); }));
					members.forEach(function (member) {
						const result = document.createElement('div');
						result.className = 'cen-mlv-search-result';
						result.setAttribute('role', 'option');
						const label = document.createElement('span');
						label.textContent = member.label;
						result.appendChild(label);
						const addButton = document.createElement('button');
						addButton.type = 'button';
						addButton.className = 'button button-small';
						const isSelected = selected.has(String(member.id));
						addButton.disabled = isSelected;
						addButton.textContent = isSelected ? CEN_MLV.strings.alreadyAdded : CEN_MLV.strings.add;
						addButton.addEventListener('click', function () {
							const id = String(member.id);
							excludedIds.delete(id);
							manualMembers.set(id, member);
							addButton.disabled = true;
							addButton.textContent = CEN_MLV.strings.alreadyAdded;
							renderMembers();
						});
						result.appendChild(addButton);
						searchResults.appendChild(result);
					});
				}
				searchResults.hidden = false;
			}).catch(function (error) {
				if (requestId !== searchRequest) return;
				searchResults.innerHTML = '';
				const message = document.createElement('p');
				message.className = 'cen-mlv-search-empty';
				message.textContent = error.message || CEN_MLV.strings.searchError;
				searchResults.appendChild(message);
				searchResults.hidden = false;
			});
		}, 250);
	});

	document.addEventListener('click', function (event) {
		if (!searchResults.contains(event.target) && event.target !== searchInput) closeSearchResults();
	});

	downloadForm.addEventListener('submit', function (event) {
		if (!combinedMembers().length) event.preventDefault();
	});

	renderMembers();
}());

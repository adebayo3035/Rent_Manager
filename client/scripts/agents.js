// client/scripts/agents.js

let agentsData = [];
let currentAgentCode = null;
let currentPropertyCode = null;
let currentRating = 0;
let currentComment = '';
let currentAgentName = '';

document.addEventListener('DOMContentLoaded', function() {
    loadPropertyAgents();
});

async function loadPropertyAgents() {
    const container = document.getElementById('agentsGrid');
    if (!container) return;

    container.innerHTML = '<div class="loading-spinner"><div class="spinner"></div><p>Loading agents...</p></div>';

    try {
        const response = await fetch('../backend/agents/fetch_property_agents.php');
        const data = await response.json();

        if (data.success && data.data.properties) {
            agentsData = data.data.properties;
            renderAgents(agentsData);
        } else {
            throw new Error(data.message || 'Failed to load agents');
        }
    } catch (error) {
        console.error('Error loading agents:', error);
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-circle"></i>
                <h3>Failed to Load Agents</h3>
                <p>Please try again later.</p>
            </div>
        `;
    }
}

function renderAgents(properties) {
    const container = document.getElementById('agentsGrid');
    
    // Group by agent (an agent may manage multiple properties)
    const agentMap = new Map();

    properties.forEach(prop => {
        if (!prop.agent_code) return;
        
        if (!agentMap.has(prop.agent_code)) {
            agentMap.set(prop.agent_code, {
                agent_code: prop.agent_code,
                agent_name: prop.agent_name,
                agent_email: prop.agent_email,
                agent_phone: prop.agent_phone,
                agent_photo: prop.agent_photo,
                avg_rating: prop.avg_rating,
                total_ratings: prop.total_ratings,
                properties: []
            });
        }
        
        agentMap.get(prop.agent_code).properties.push({
            property_code: prop.property_code,
            property_name: prop.property_name,
            property_unit: prop.property_unit,
            total_units: prop.total_units,
            occupied_units: prop.occupied_units,
            occupancy_rate: prop.occupancy_rate,
            my_rating: prop.my_rating,
            my_comment: prop.my_comment
        });
    });

    if (agentMap.size === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-user-tie"></i>
                <h3>No Agents Assigned</h3>
                <p>No agents are currently assigned to your properties.</p>
            </div>
        `;
        return;
    }

    container.innerHTML = Array.from(agentMap.values()).map(agent => `
        <div class="agent-card">
            <div class="agent-header">
                <div class="agent-avatar">
                    ${agent.agent_photo ? 
                        `<img src="../../admin/backend/agents/agent_photos/${agent.agent_photo}" alt="${agent.agent_name}" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(agent.agent_name)}&background=667eea&color=fff'">` :
                        `<i class="fas fa-user-circle"></i>`
                    }
                </div>
                <div class="agent-name">${escapeHtml(agent.agent_name)}</div>
                <div class="rating-stars">
                    <div class="stars">${renderStars(agent.avg_rating)}</div>
                    <span class="rating-count">(${agent.total_ratings} reviews)</span>
                </div>
                <div class="agent-stats">
                    <div class="agent-stat">
                        <div class="agent-stat-value">${agent.properties.length}</div>
                        <div class="agent-stat-label">Properties</div>
                    </div>
                    <div class="agent-stat">
                        <div class="agent-stat-value">${agent.properties.reduce((sum, p) => sum + p.total_units, 0)}</div>
                        <div class="agent-stat-label">Units</div>
                    </div>
                </div>
            </div>
            <div class="agent-body">
                <div class="agent-property">
                    <div class="property-name">
                        <i class="fas fa-building"></i>
                        ${escapeHtml(agent.properties[0].property_name)}
                        ${agent.properties.length > 1 ? `<span class="badge">+${agent.properties.length - 1} more</span>` : ''}
                    </div>
                    <div class="property-stats">
                        <span><i class="fas fa-chart-pie"></i>Capacity: ${agent.properties[0].property_unit}</span>
                        <span><i class="fas fa-chart-pie"></i> ${agent.properties[0].occupancy_rate}% occupied</span>
                        <span><i class="fas fa-door-open"></i> ${agent.properties[0].occupied_units}/${agent.properties[0].total_units} units</span>
                    </div>
                </div>
                
                <div class="agent-contact">
                    <div class="contact-item">
                        <i class="fas fa-envelope"></i>
                        <span>${escapeHtml(agent.agent_email)}</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-phone"></i>
                        <span>${escapeHtml(agent.agent_phone)}</span>
                    </div>
                </div>
                
                ${agent.properties[0].my_rating ? `
                <div class="my-rating">
                    <i class="fas fa-star"></i>
                    Your rating: ${agent.properties[0].my_rating}/5
                    ${agent.properties[0].my_comment ? `<div class="my-comment">"${escapeHtml(agent.properties[0].my_comment)}"</div>` : ''}
                </div>
                ` : ''}
                
                <div class="agent-actions">
                    <button class="btn-view" type="button" data-action="view-agent" data-agent-code="${escapeAttribute(agent.agent_code)}">
                        <i class="fas fa-info-circle"></i> View Details
                    </button>
                    <button
                        class="btn-rate"
                        type="button"
                        data-action="rate-agent"
                        data-agent-code="${escapeAttribute(agent.agent_code)}"
                        data-property-code="${escapeAttribute(agent.properties[0].property_code)}"
                        data-agent-name="${escapeAttribute(agent.agent_name)}"
                        data-current-rating="${Number(agent.properties[0].my_rating) || 0}"
                        data-current-comment="${escapeAttribute(agent.properties[0].my_comment || '')}"
                    >
                        <i class="fas fa-star"></i> ${agent.properties[0].my_rating ? 'Update Rating' : 'Rate Agent'}
                    </button>
                </div>
            </div>
        </div>
    `).join('');

    bindAgentCardActions(container);
}

function bindAgentCardActions(container) {
    container.onclick = function(event) {
        const button = event.target.closest('button[data-action]');
        if (!button || !container.contains(button)) return;

        if (button.dataset.action === 'view-agent') {
            viewAgentDetails(button.dataset.agentCode);
            return;
        }

        if (button.dataset.action === 'rate-agent') {
            openRateModal(
                button.dataset.agentCode,
                button.dataset.propertyCode,
                button.dataset.agentName,
                Number(button.dataset.currentRating) || 0,
                button.dataset.currentComment || ''
            );
        }
    };
}

function renderStars(rating) {
    rating = parseFloat(rating) || 0;
    let stars = '';
    for (let i = 1; i <= 5; i++) {
        if (i <= Math.floor(rating)) {
            stars += '<i class="fas fa-star"></i>';
        } else if (i - 0.5 <= rating) {
            stars += '<i class="fas fa-star-half-alt"></i>';
        } else {
            stars += '<i class="far fa-star"></i>';
        }
    }
    return stars;
}

async function viewAgentDetails(agentCode) {
    try {
        const response = await fetch(`../backend/agents/fetch_agent_details.php?agent_code=${agentCode}`);
        const data = await response.json();

        if (data.success) {
            showAgentDetailsModal(data.data);
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        console.error('Error fetching agent details:', error);
        if (window.showToast) {
            window.showToast(error.message || 'Failed to load agent details', 'error');
        } else {
            alert(error.message || 'Failed to load agent details');
        }
    }
}

function showAgentDetailsModal(agentData) {
    // Calculate average rating from properties
    let totalRating = 0;
    let ratingCount = 0;
    if (agentData.properties) {
        agentData.properties.forEach(prop => {
            if (prop.avg_rating && prop.avg_rating > 0) {
                totalRating += parseFloat(prop.avg_rating);
                ratingCount++;
            }
        });
    }
    const overallRating = ratingCount > 0 ? (totalRating / ratingCount).toFixed(1) : 0;

    const modalBody = document.getElementById('agentDetailsBody');
    if (!modalBody) return;

    modalBody.innerHTML = `
        <div class="agent-profile-header">
            <div class="agent-avatar-large">
                ${agentData.agent.photo ? 
                    `<img src="../../admin/backend/agents/agent_photos/${agentData.agent.photo}" alt="${agentData.agent_name}" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(agentData.agent_name)}&background=667eea&color=fff'">` :
                    `<i class="fas fa-user-circle"></i>`
                }
            </div>
            <div class="agent-info">
                <h2>${escapeHtml(agentData.agent.firstname)}  ${escapeHtml(agentData.agent.lastname)}</h2>
                <div class="rating-stars-large">
                    ${renderStars(overallRating)}
                    <span>(${ratingCount} reviews overall)</span>
                </div>
                <div class="agent-contact-details">
                    <p><i class="fas fa-envelope"></i> ${escapeHtml(agentData.agent.email)}</p>
                    <p><i class="fas fa-phone"></i> ${escapeHtml(agentData.agent.phone)}</p>
                </div>
            </div>
        </div>
        
        <div class="agent-properties-section">
            <h4><i class="fas fa-building"></i> Properties Managed (${agentData.properties?.length || 0})</h4>
            <div class="properties-list">
                ${agentData.properties?.map(prop => `
                    <div class="property-item">
                        <div class="property-header">
                            <strong>${escapeHtml(prop.property_name)}</strong>
                            <span class="property-rating">${renderStars(prop.avg_rating)}</span>
                        </div>
                        <div class="property-stats-details">
                            <span><i class="fas fa-door-closed"></i> Property Capacity: ${prop.property_capacity}</span>
                            <span><i class="fas fa-door-closed"></i> Created Units: ${prop.total_units}</span>
                            <span><i class="fas fa-door-open"></i> Occupied: ${prop.occupied_units}</span>
                            <span><i class="fas fa-chart-pie"></i> Occupancy: ${agentData.agent.occupancy_rate}%</span>
                        </div>
                        ${prop.my_rating ? `
                            <div class="my-rating-details">
                                <i class="fas fa-star"></i> Your rating: ${prop.my_rating}/5
                                ${prop.my_comment ? `<p class="my-comment">"${escapeHtml(prop.my_comment)}"</p>` : ''}
                            </div>
                        ` : ''}
                    </div>
                `).join('') || '<p>No properties found</p>'}
            </div>
        </div>
    `;

    const modal = document.getElementById('agentDetailsModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function openRateModal(agentCode, propertyCode, agentName, currentRatingValue = 0, currentCommentValue = '') {
    currentAgentCode = agentCode;
    currentPropertyCode = propertyCode;
    currentRating = currentRatingValue;
    currentComment = currentCommentValue;
    currentAgentName = agentName;

    const modalBody = document.getElementById('rateAgentBody');
    if (!modalBody) return;

    modalBody.innerHTML = `
        <div class="rate-agent-info">
            <p>Rate <strong>${escapeHtml(agentName)}</strong> for managing your property</p>
        </div>
        
        <div class="rating-input">
            <label>Your Rating *</label>
            <div class="star-rating" id="starRating">
                ${[1, 2, 3, 4, 5].map(star => `
                    <i class="fas fa-star ${star <= currentRatingValue ? 'active' : ''}" 
                       data-rating="${star}" 
                       onmouseover="highlightStars(${star})" 
                       onmouseout="resetStars(${currentRatingValue})"
                       onclick="setRating(${star})"></i>
                `).join('')}
            </div>
            <input type="hidden" id="ratingValue" value="${currentRatingValue}">
        </div>
        
        <div class="comment-input">
            <label>Your Comment (Optional)</label>
            <textarea id="ratingComment" rows="4" placeholder="Share your experience with this agent...">${escapeHtml(currentCommentValue)}</textarea>
        </div>
    `;

    const modal = document.getElementById('rateAgentModal');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function highlightStars(rating) {
    const stars = document.querySelectorAll('#starRating i');
    stars.forEach((star, index) => {
        if (index < rating) {
            star.classList.add('hover');
        } else {
            star.classList.remove('hover');
        }
    });
}

function resetStars(currentRatingValue) {
    const stars = document.querySelectorAll('#starRating i');
    stars.forEach((star, index) => {
        star.classList.remove('hover');
        if (index < currentRatingValue) {
            star.classList.add('active');
        } else {
            star.classList.remove('active');
        }
    });
}

function setRating(rating) {
    currentRating = rating;
    document.getElementById('ratingValue').value = rating;
    
    const stars = document.querySelectorAll('#starRating i');
    stars.forEach((star, index) => {
        if (index < rating) {
            star.classList.add('active');
        } else {
            star.classList.remove('active');
        }
    });
}

async function submitRating() {
    const rating = currentRating;
    const comment = document.getElementById('ratingComment')?.value.trim() || '';
    
    if (rating === 0) {
        if (window.showToast) {
            window.showToast('Please select a rating', 'error');
        } else {
            alert('Please select a rating');
        }
        return;
    }

    const submitBtn = document.querySelector('#rateAgentModal .btn-primary');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    submitBtn.disabled = true;

    try {
        const response = await fetch('../backend/agents/rate_agent.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                agent_code: currentAgentCode,
                property_code: currentPropertyCode,
                rating: rating,
                comment: comment
            })
        });

        const data = await response.json();

        if (data.success) {
            if (window.showToast) {
                window.showToast(data.message || 'Rating submitted successfully!', 'success');
            } else {
                alert(data.message || 'Rating submitted successfully!');
            }
            
            closeRateAgentModal();
            // Reload agents to show updated rating
            setTimeout(() => loadPropertyAgents(), 1000);
        } else {
            throw new Error(data.message || 'Failed to submit rating');
        }
    } catch (error) {
        console.error('Error submitting rating:', error);
        if (window.showToast) {
            window.showToast(error.message, 'error');
        } else {
            alert(error.message);
        }
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

function closeAgentDetailsModal() {
    const modal = document.getElementById('agentDetailsModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function closeRateAgentModal() {
    const modal = document.getElementById('rateAgentModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeAttribute(text) {
    return escapeHtml(String(text ?? ''));
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        event.target.setAttribute('aria-hidden', 'true');
    }
}

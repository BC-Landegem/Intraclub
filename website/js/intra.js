presentPlayers = [];
allPlayers = [];

function loadRanking() {
    fetch('https://www.bclandegem.be/intraclub-api/index.php/rankings/general')
        .then(response => response.json())
        .then(data => {
            allPlayers = data.general;
            data.general.forEach((item, index) => {
                const rank = item.rank;
                const name = item.firstName + ' ' + item.name;
                const points = item.points;

                const rankElement = document.createElement('div');
                rankElement.classList.add('grid-item');
                rankElement.textContent = rank;

                const nameElement = document.createElement('div');
                nameElement.classList.add('grid-item');
                nameElement.textContent = name;

                const pointsElement = document.createElement('div');
                pointsElement.classList.add('grid-item');
                pointsElement.textContent = points;


                const button = document.createElement("button");
                button.classList.add('btn', 'btn-success', 'btn-lg', 'me-3');
                //add fa-check icon inside
                const icon = document.createElement('i');
                icon.classList.add('fa', 'fa-check');
                button.appendChild(icon);
                button.onclick = function () {
                    addPlayerPresent(item.id);
                    updatePresentButton(button, item.id);
                };
                pointsElement.appendChild(button);
                const label = document.createElement('span');
                label.classList.add('badge', 'badge-danger');
                label.textContent = 'Nog niet gezien';
                pointsElement.appendChild(label);

                document.querySelector('.grid-container').appendChild(rankElement);
                document.querySelector('.grid-container').appendChild(nameElement);
                document.querySelector('.grid-container').appendChild(pointsElement);
            });
        })
        .catch(error => console.error(error));
}

function addPlayerPresent(id) {
    //find player in allPlayers
    const player = allPlayers.find(player => player.id === id);
    //add player to presentPlayers
    presentPlayers.push(player);
    const span = document.getElementById('playersPresent');
    span.textContent = "Aantal spelers: " + presentPlayers.length;
}
function updatePresentButton(button, id) {
    var span = button.nextSibling;
    span.textContent = 'Is hier!';
    span.classList.remove('badge-danger');
    span.classList.add('badge-success');

    button.classList.remove('btn-success');
    button.classList.add('btn-danger');
    //update icon inside
    var icon = button.firstChild;
    icon.classList.remove('fa-check');
    icon.classList.add('fa-ban');

    button.appendChild(icon);
    button.onclick = function () {
        removePlayerPresent(id);
        updateAbsentButton(button, id);
    };

}
function updateAbsentButton(button, id) {
    var span = button.nextSibling;
    span.textContent = 'Nog niet gezien';
    span.classList.remove('badge-success');
    span.classList.add('badge-danger');
    button.classList.remove('btn-danger');
    button.classList.add('btn-success');
    var icon = button.firstChild;
    icon.classList.remove('fa-ban');
    icon.classList.add('fa-check');
    button.onclick = function () {
        addPlayerPresent(id);
        updatePresentButton(button, id);
    };
}
function removePlayerPresent(id) {
    //find player in presentPlayers
    const player = presentPlayers.find(player => player.id === id);
    //remove player from presentPlayers
    presentPlayers.splice(presentPlayers.indexOf(player), 1);
    const span = document.getElementById('playersPresent');
    span.textContent = "Aantal spelers: " + presentPlayers.length;
}


function generateMatches() {
    // Sort presentPlayers by rank
    presentPlayers.sort((a, b) => (a.rank > b.rank) ? 1 : -1);
    // Create two groups, which consist of 75% of the players
    // Overlap between those two groups is 50%
    const firstGroup = presentPlayers.slice(0, Math.floor(presentPlayers.length * 0.75));
    const secondGroup = presentPlayers.slice(Math.floor(presentPlayers.length * 0.25), presentPlayers.length);

    // Create matches
    const matches = [];
    // 1 match = 4 players
    // Create match with 4 random players from first group
    // Then match with 4 random players from second group
    // Repeat until all players are matched (or less than 4 players left)
    // Don't forget to remove matched players from the groups


}
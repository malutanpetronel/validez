# Validez - Frontend, imagine de DEZVOLTARE (React dev server + Cordova browser).
FROM node:20

WORKDIR /app

COPY package*.json ./
RUN npm install
RUN npm install -g cordova

# NB: react.cordova (`reco`) nu se foloseste - incompatibil cu Node modern (lectie ArtaNFT):
#   reco serve -> react-scripts start ; reco copy -> cp build/* www/ (in scriptul build)

COPY . .

EXPOSE 3000

# platforms/ e gitignorat si ascuns de volumul montat: platforma browser se adauga la fiecare
# pornire (idempotent), ca evenimentul cordova "deviceready" sa se declanseze si in browser.
CMD ["sh", "-c", "cordova platform add browser 2>/dev/null || true; cordova prepare browser 2>/dev/null || true; npm start"]

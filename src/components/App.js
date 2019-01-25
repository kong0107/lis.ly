import React, { Component } from 'react';
import {
  BrowserRouter as Router,
  Route,
  Switch,
  Link
} from "react-router-dom";

import LawList from './LawList';
import Law from './Law';

import '../styles/App.css';

class App extends Component {
  render() {
    return (
      <Router basename="/lis.ly/">
        <div className="App">
          <header>
            <Link className="App-link" to="/">法律查詢</Link>
          </header>
          <main>
            <Switch>
              <Route path="/" exact component={LawList} />
              <Route path="/laws/:id" component={Law} />
            </Switch>
          </main>
          <footer>
            <a className="App-footer-link" href="https://github.com/kong0107/lis.ly">開放資料及擷取程式</a>
          </footer>
        </div>
      </Router>
    );
  }
}

export default App;

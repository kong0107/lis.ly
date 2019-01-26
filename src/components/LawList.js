import React from 'react';
import {
  Link
} from "react-router-dom";

import CSVParse from 'csv-parse/lib/sync';

import {
  fetch2,
  errorHandler as eh
} from '../libs/utilities';

export default class LawList extends React.PureComponent {
  constructor(props) {
    super(props);
    this.state = {
      query: '',
      laws: []
    };
  }

  componentDidMount() {
    fetch2('https://cdn.jsdelivr.net/gh/kong0107/lis.ly@catalogue/laws.csv')
    .then(res => res.text())
    .then(csv => {
      const records = CSVParse(csv, {
        skip_empty_lines: true,
        columns: ['id', 'name', 'type']
      });
      records.shift(); // removes the first line
      this.setState({laws: records});
    })
    .catch(eh);
    document.title = '法律查詢';
  }

  render() {
    const q = this.state.query.trim();
    const matchedLaws = q
      ? this.state.laws.filter(law => law.name.indexOf(q) !== -1)
      : this.state.laws
    ;
    return (
      <div>
        <input style={{margin: '1em 0'}}
          onInput={se => this.setState({query: se.target.value})}
          placeholder="搜尋名稱"
        />
        <div>{q ? '找到' : '共有'} {matchedLaws.length} 部法律</div>
        <ul>
          {matchedLaws.map(law =>
            <li key={law.id} style={{margin: '.2em 0'}}>
              <code>{law.id}</code>
              <Link to={`/laws/${law.id}`}>{law.name}</Link>
            </li>
          )}
        </ul>
      </div>
    );
  }
};

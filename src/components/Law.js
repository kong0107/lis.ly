import React from 'react';
//import { Link } from "react-router-dom";
import {
  fetch2,
  errorHandler as eh
} from '../libs/utilities';

import '../styles/Law.css';

export default class Law extends React.PureComponent {
  constructor(props) {
    super(props);
    this.state = {
      query: '',
      law: {
        versions: [],
        types: [],
        law_data: []
      }
    }
  }

  componentDidMount() {
    fetch2(`https://cdn.jsdelivr.net/gh/kong0107/lis.ly@json/${this.props.match.params.id}.json`)
    .then(res => res.json())
    .then(law => {
      document.title = law.title;

      if(law.law_reasons) {
        for(let number in law.law_reasons) {
          law.law_data
          .find(item => item.rule_no === number)
          .reason = law.law_reasons[number];
        }
      }
      this.setState({law});

      if(window.location.hash) {
        const hash = window.location.hash;
        window.location.hash = '';
        window.location.hash = hash;
      }
    })
    .catch(eh);
  }

  render() {
    const law = this.state.law;
    const q = this.state.query.trim();
    const content = q
      ? law.law_data.filter(item => item.content && (item.content.indexOf(q) !== -1))
      : law.law_data
    ;
    return (
      <div className="Law">
        <header>
          <h1>{law.title}</h1>
          <ol>
            {law.versions.map((item, index) => <li key={index}>{item}</li>)}
          </ol>
          {law.deprecated_reason &&
            <div className="deprecation">
              <strong>已廢止</strong>
              {law.deprecated_reason.split('\n').map((line, index) => <p key={index}>{line.trim()}</p>)}
            </div>
          }
          <input
            onInput={se => this.setState({query: se.target.value})}
            placeholder="搜尋法條"
          />
        </header>
        <div>
          {law.law_data.length
            ? content.map(renderContentItem)
            : '讀取中'
          }
        </div>
      </div>
    );
  }
};

const renderContentItem = item => {
  if(item.section_name) {
    const m = item.section_name.match(/第[一二三四五六七八九十]+([編章節款目])/);
    const level = '編章節款目'.indexOf(m[1]) + 2;
    return React.createElement('h' + level, {key: item.section_name}, item.section_name);
  }
  const key = item.rule_no || 'preamble';
  return (
    <article key={key} id={key}>
      <header>
        <span className="rule_no">{item.rule_no}</span>
        <span className="note">{item.note}</span>
      </header>
      <ol>
        {item.content.split('\n').map((line, index) =>
          <li key={index}
            className="articleLine"
          >{line.trim()}</li>
        )}
      </ol>
      {Object.keys(item.relates).map(type => renderRelates(item.relates[type], type))}
      {item.reason &&
        <dl className="reason">
          <dt>修正理由</dt>
          {item.reason.split('\n').map((line, index) => <dd key={index}>{line.trim()}</dd>)}
        </dl>
      }
    </article>
  );
};

const renderRelates = (groups, type) => {
  return (
    <dl key={type} className="relates">
      <dt>{type}</dt>
      {groups.map(articlesInOneLaw => {
        const articles = articlesInOneLaw.numbers.map(num =>
          <a key={num}
            href={`${articlesInOneLaw.law_no}#${num}`}
          >{num}</a>
        );
        for(let i = articles.length - 1; i > 0; --i)
          articles.splice(i, 0, '、');
        return (
          <dd key={articlesInOneLaw.law_no}>
            <a href={`${articlesInOneLaw.law_no}`}>{articlesInOneLaw.law_name}</a>
            ：{articles}
          </dd>
        );
      })}
    </dl>
  );
};
